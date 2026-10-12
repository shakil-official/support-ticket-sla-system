<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/** Server side authorization: every rule is tested through real HTTP requests. */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Notification::fake();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('tickets.index'))->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_customer_sees_only_own_tickets_in_the_list(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $own = Ticket::factory()->createdBy($mine)->create(['subject' => 'My own problem']);
        Ticket::factory()->createdBy($other)->create(['subject' => 'Somebody else problem']);

        $this->actingAs($mine)->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('My own problem')
            ->assertDontSee('Somebody else problem');

        $this->actingAs($mine)->get(route('tickets.show', $own))->assertOk();
    }

    public function test_customer_cannot_open_edit_or_reply_to_another_customers_ticket(): void
    {
        $intruder = User::factory()->create();
        $ticket = Ticket::factory()->createdBy(User::factory()->create())->create();

        $this->actingAs($intruder)->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($intruder)->get(route('tickets.edit', $ticket))->assertForbidden();
        $this->actingAs($intruder)->put(route('tickets.update', $ticket), ['subject' => 'x'])->assertForbidden();
        $this->actingAs($intruder)->post(route('tickets.replies.store', $ticket), ['body' => 'hi'])->assertForbidden();
        $this->actingAs($intruder)->delete(route('tickets.destroy', $ticket))->assertForbidden();
    }

    public function test_agent_cannot_see_or_touch_a_ticket_assigned_to_another_agent(): void
    {
        $agent = User::factory()->agent()->create();
        $theirs = Ticket::factory()->assignedTo(User::factory()->agent()->create())->create();

        $this->actingAs($agent)->get(route('tickets.show', $theirs))->assertForbidden();
        $this->actingAs($agent)->post(route('tickets.replies.store', $theirs), ['body' => 'hi'])->assertForbidden();
        $this->actingAs($agent)->patch(route('tickets.status', $theirs), ['to' => 'resolved'])->assertForbidden();
    }

    public function test_agent_can_see_the_unassigned_pool_and_their_own_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $pool = Ticket::factory()->create(['subject' => 'Pool ticket']);
        Ticket::factory()->assignedTo($agent)->create(['subject' => 'Mine ticket']);
        Ticket::factory()->assignedTo(User::factory()->agent()->create())->create(['subject' => 'Foreign ticket']);

        $this->actingAs($agent)->get(route('tickets.index'))
            ->assertSee('Pool ticket')
            ->assertSee('Mine ticket')
            ->assertDontSee('Foreign ticket');

        $this->actingAs($agent)->get(route('tickets.show', $pool))->assertOk();
    }

    public function test_only_admins_reach_the_admin_areas(): void
    {
        foreach ([User::factory()->agent()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
            $this->actingAs($user)->get(route('imports.index'))->assertForbidden();
        }

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('imports.index'))->assertOk();
    }

    public function test_registration_cannot_create_an_admin(): void
    {
        $this->post('/register', [
            'name' => 'Mallory', 'email' => 'mallory@example.com',
            'password' => 'password-123', 'password_confirmation' => 'password-123',
            'role' => 'admin', 'is_available' => 1,
        ]);

        $user = User::query()->where('email', 'mallory@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isCustomer());
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), ['role' => 'customer'])
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    // ---- internal notes ---------------------------------------------------------------

    public function test_customers_never_see_internal_notes(): void
    {
        $customer = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->createdBy($customer)->assignedTo($agent)->create();
        TicketReply::factory()->for($ticket)->create(['user_id' => $agent->id, 'body' => 'Public answer text']);
        TicketReply::factory()->for($ticket)->internal()->create(['user_id' => $agent->id, 'body' => 'SECRET internal note']);

        $this->actingAs($customer)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Public answer text')
            ->assertDontSee('SECRET internal note');

        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertSee('SECRET internal note');
    }

    public function test_customers_cannot_create_internal_notes(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->createdBy($customer)->create();

        $this->actingAs($customer)
            ->post(route('tickets.replies.store', $ticket), ['body' => 'sneaky', 'is_internal' => 1])
            ->assertForbidden();

        $this->assertSame(0, $ticket->replies()->count());
    }

    public function test_internal_notes_do_not_notify_the_customer(): void
    {
        $customer = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->createdBy($customer)->assignedTo($agent)->create();

        $this->actingAs($agent)->post(route('tickets.replies.store', $ticket), ['body' => 'note', 'is_internal' => 1])->assertRedirect();

        Notification::assertNothingSentTo($customer);
    }

    // ---- attachments ------------------------------------------------------------------

    public function test_attachment_download_follows_ticket_and_internal_note_visibility(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->createdBy($customer)->assignedTo($agent)->create();

        $publicFile = $this->attachment($ticket, null, $agent);
        $note = TicketReply::factory()->for($ticket)->internal()->create(['user_id' => $agent->id]);
        $noteFile = $this->attachment($ticket, $note, $agent);

        $this->actingAs($customer)->get(route('attachments.download', $publicFile))->assertOk();
        $this->actingAs($stranger)->get(route('attachments.download', $publicFile))->assertForbidden();
        $this->actingAs($customer)->get(route('attachments.download', $noteFile))->assertForbidden();
        $this->actingAs($agent)->get(route('attachments.download', $noteFile))->assertOk();
    }

    private function attachment(Ticket $ticket, ?TicketReply $reply, User $uploader): Attachment
    {
        Storage::disk('local')->put($path = "tickets/{$ticket->id}/".fake()->uuid().'.txt', 'content');

        return Attachment::create([
            'ticket_id' => $ticket->id, 'reply_id' => $reply?->id, 'uploaded_by' => $uploader->id,
            'original_name' => 'file.txt', 'stored_name' => basename($path), 'disk' => 'local',
            'path' => $path, 'mime' => 'text/plain', 'size' => 7, 'checksum' => hash('sha256', 'content'),
        ]);
    }
}
