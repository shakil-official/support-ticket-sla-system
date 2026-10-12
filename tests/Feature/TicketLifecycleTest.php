<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketResolvedNotification;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class TicketLifecycleTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Notification::fake();
        Storage::fake('local');
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'subject' => 'Printer on fire', 'description' => 'It really is.',
            'priority' => 'high', 'category_id' => $this->category->id,
        ], $override);
    }

    public function test_customer_creates_a_ticket_with_number_sla_and_history(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('tickets.store'), $this->payload())->assertRedirect();

        $ticket = Ticket::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{6}$/', $ticket->ticket_number);
        $this->assertSame($customer->id, $ticket->created_by);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(8 * 60, $ticket->sla_resolution_minutes);
        $this->assertTrue($ticket->due_at->isFuture());
        $this->assertSame('created', $ticket->activities()->first()->type->value);
        Notification::assertSentTo($customer, TicketCreatedNotification::class);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('tickets.store'), ['subject' => '', 'priority' => 'urgent', 'category_id' => 9999])
            ->assertSessionHasErrors(['subject', 'description', 'priority', 'category_id']);

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_attachments_are_validated_and_stored_privately_under_a_random_name(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('tickets.store'), $this->payload([
            'attachments' => [UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')],
        ]))->assertSessionHasNoErrors();

        $file = Ticket::query()->firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('report.pdf', $file->original_name);
        $this->assertNotSame('report.pdf', $file->stored_name);
        Storage::disk('local')->assertExists($file->path);

        $this->actingAs($customer)->post(route('tickets.store'), $this->payload([
            'attachments' => [UploadedFile::fake()->create('shell.php', 1, 'application/x-php')],
        ]))->assertSessionHasErrors('attachments.0');

        $this->actingAs($customer)->post(route('tickets.store'), $this->payload([
            'attachments' => [UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf')],
        ]))->assertSessionHasErrors('attachments.0');
    }

    public function test_changing_priority_recalculates_the_sla_and_logs_the_change(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = app(TicketService::class)->create(User::factory()->create(), $this->payload(['priority' => 'medium']));
        $before = $ticket->due_at;

        app(TicketService::class)->update($ticket, $agent, ['priority' => TicketPriority::Critical->value]);

        $ticket->refresh();
        $this->assertSame(120, $ticket->sla_resolution_minutes);
        $this->assertTrue($ticket->due_at->lt($before));
        $this->assertTrue($ticket->activities()->where('type', 'priority_changed')->exists());
    }

    public function test_agent_reply_waits_for_customer_and_customer_reply_resumes_work(): void
    {
        $customer = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->createdBy($customer)->assignedTo($agent)->status(TicketStatus::InProgress)->create();

        $this->actingAs($agent)->post(route('tickets.replies.store', $ticket), ['body' => 'Please send logs'])->assertRedirect();
        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingForCustomer, $ticket->status);
        $this->assertNotNull($ticket->first_response_at);
        Notification::assertSentTo($customer, TicketReplyNotification::class);

        $this->actingAs($customer)->post(route('tickets.replies.store', $ticket), ['body' => 'Here they are'])->assertRedirect();
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        Notification::assertSentTo($agent, TicketReplyNotification::class);
    }

    public function test_resolving_notifies_the_customer_and_blocks_further_editing(): void
    {
        $customer = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->createdBy($customer)->assignedTo($agent)->status(TicketStatus::InProgress)->create();

        $this->actingAs($agent)->patch(route('tickets.status', $ticket), ['to' => 'resolved'])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        Notification::assertSentTo($customer, TicketResolvedNotification::class);
        $this->actingAs($agent)->get(route('tickets.edit', $ticket))->assertForbidden();
    }

    public function test_customer_can_confirm_closure_but_not_close_an_unresolved_ticket(): void
    {
        $customer = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $open = Ticket::factory()->createdBy($customer)->assignedTo($agent)->status(TicketStatus::InProgress)->create();
        $resolved = Ticket::factory()->createdBy($customer)->assignedTo($agent)->resolved()->create();

        $this->actingAs($customer)->patch(route('tickets.status', $open), ['to' => 'closed'])->assertForbidden();
        $this->actingAs($customer)->patch(route('tickets.status', $resolved), ['to' => 'closed'])->assertRedirect();

        $this->assertSame(TicketStatus::Closed, $resolved->fresh()->status);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $closed = Ticket::factory()->closed()->create();

        $this->actingAs($admin)->patch(route('tickets.status', $closed), ['to' => 'open'])->assertForbidden();
        $this->assertSame(TicketStatus::Closed, $closed->fresh()->status);
    }

    public function test_active_and_resolved_tabs_are_separate(): void
    {
        $admin = User::factory()->admin()->create();
        Ticket::factory()->create(['subject' => 'Still open one']);
        Ticket::factory()->resolved()->create(['subject' => 'Already solved one']);

        $this->actingAs($admin)->get(route('tickets.index'))->assertSee('Still open one')->assertDontSee('Already solved one');
        $this->actingAs($admin)->get(route('tickets.index', ['view' => 'resolved']))->assertSee('Already solved one')->assertDontSee('Still open one');
    }
}
