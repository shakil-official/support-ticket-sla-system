<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\TicketAssignmentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class TicketAssignmentTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Notification::fake();
    }

    // ---- concurrency: the "two agents accept the same ticket" scenario ---------------

    public function test_second_agent_cannot_claim_an_already_claimed_ticket(): void
    {
        [$a, $b] = User::factory()->agent()->count(2)->create();
        $ticket = Ticket::factory()->create();
        $service = app(TicketAssignmentService::class);

        // Both agents hold the SAME stale, still-unassigned copy of the ticket
        // (exactly what two browsers looking at the list would have).
        $staleForA = Ticket::find($ticket->id);
        $staleForB = Ticket::find($ticket->id);

        $service->claim($staleForA, $a);

        $this->expectException(TicketAlreadyAssignedException::class);
        try {
            $service->claim($staleForB, $b);
        } finally {
            $this->assertSame($a->id, $ticket->fresh()->assigned_agent_id, 'the first claimer keeps the ticket');
        }
    }

    public function test_a_claim_moves_the_ticket_to_in_progress_and_writes_history(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        app(TicketAssignmentService::class)->claim($ticket, $agent);

        $fresh = $ticket->fresh();
        $this->assertSame($agent->id, $fresh->assigned_agent_id);
        $this->assertSame(TicketStatus::InProgress, $fresh->status);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $ticket->id)->where('type', 'assigned')->exists());
    }

    public function test_claim_endpoint_shows_a_friendly_message_to_the_loser(): void
    {
        [$a, $b] = User::factory()->agent()->count(2)->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($a)->post(route('tickets.claim', $ticket))->assertRedirect(route('tickets.show', $ticket));

        // B can no longer even see the ticket (it belongs to A) -> forbidden, never a double assignment.
        $this->actingAs($b)->post(route('tickets.claim', $ticket))->assertForbidden();
        $this->assertSame($a->id, $ticket->fresh()->assigned_agent_id);
    }

    public function test_customers_cannot_claim(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->createdBy($customer)->create();

        $this->actingAs($customer)->post(route('tickets.claim', $ticket))->assertForbidden();
        $this->assertNull($ticket->fresh()->assigned_agent_id);
    }

    // ---- manual assignment -----------------------------------------------------------

    public function test_admin_can_assign_and_reassign(): void
    {
        $admin = User::factory()->admin()->create();
        [$a, $b] = User::factory()->agent()->count(2)->create();
        $ticket = Ticket::factory()->create();
        $service = app(TicketAssignmentService::class);

        $service->assign($ticket, $a, $admin);
        $this->assertSame($a->id, $ticket->fresh()->assigned_agent_id);

        $service->assign($ticket, $b, $admin);
        $this->assertSame($b->id, $ticket->fresh()->assigned_agent_id);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $ticket->id)->where('type', 'reassigned')->exists());
    }

    public function test_tickets_can_only_be_assigned_to_agents(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->expectException(DomainException::class);
        app(TicketAssignmentService::class)->assign(Ticket::factory()->create(), $customer, $admin);
    }

    public function test_agents_cannot_use_the_assign_endpoint(): void
    {
        $agent = User::factory()->agent()->create();
        $other = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($agent)->post(route('tickets.assign', $ticket), ['agent_id' => $other->id])->assertForbidden();
    }

    // ---- automatic assignment --------------------------------------------------------

    public function test_auto_assign_picks_the_agent_with_the_fewest_active_tickets(): void
    {
        $busy = User::factory()->agent()->create();
        $free = User::factory()->agent()->create();
        Ticket::factory()->count(3)->assignedTo($busy)->create();

        $result = app(TicketAssignmentService::class)->autoAssign(Ticket::factory()->create());

        $this->assertSame($free->id, $result->assigned_agent_id);
    }

    public function test_resolved_tickets_do_not_count_as_workload(): void
    {
        $a = User::factory()->agent()->create();
        $b = User::factory()->agent()->create();
        Ticket::factory()->count(5)->assignedTo($a)->resolved()->create();
        Ticket::factory()->assignedTo($b)->create();

        $result = app(TicketAssignmentService::class)->autoAssign(Ticket::factory()->create());

        $this->assertSame($a->id, $result->assigned_agent_id);
    }

    public function test_auto_assign_ignores_unavailable_agents(): void
    {
        User::factory()->agent()->unavailable()->create();
        $available = User::factory()->agent()->create();

        $result = app(TicketAssignmentService::class)->autoAssign(Ticket::factory()->create());

        $this->assertSame($available->id, $result->assigned_agent_id);
    }

    public function test_auto_assign_prefers_agents_skilled_in_the_category(): void
    {
        $billing = Category::factory()->create();
        $skilled = User::factory()->agent()->create();
        $skilled->categories()->attach($billing);
        $generalist = User::factory()->agent()->create();
        Ticket::factory()->count(2)->assignedTo($skilled)->create(); // busier, but skilled

        $result = app(TicketAssignmentService::class)->autoAssign(Ticket::factory()->inCategory($billing)->create());

        $this->assertSame($skilled->id, $result->assigned_agent_id);
        $this->assertNotSame($generalist->id, $result->assigned_agent_id);
    }

    public function test_ties_go_to_the_agent_who_was_assigned_longest_ago(): void
    {
        $recent = User::factory()->agent()->create(['last_assigned_at' => now()->subMinutes(5)]);
        $older = User::factory()->agent()->create(['last_assigned_at' => now()->subDay()]);

        $result = app(TicketAssignmentService::class)->autoAssign(Ticket::factory()->create());

        $this->assertSame($older->id, $result->assigned_agent_id);
    }

    public function test_without_any_available_agent_the_ticket_stays_unassigned_and_it_is_recorded(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertNull(app(TicketAssignmentService::class)->autoAssign($ticket));
        $this->assertNull($ticket->fresh()->assigned_agent_id);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $ticket->id)->where('type', 'assignment_failed')->exists());
    }
}
