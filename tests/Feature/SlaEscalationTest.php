<?php

namespace Tests\Feature;

use App\Enums\SlaState;
use App\Jobs\EscalateTicketSla;
use App\Jobs\WarnTicketSla;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Services\Sla\SlaEscalationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class SlaEscalationTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_sla_state_is_derived_from_the_deadline(): void
    {
        $this->assertSame(SlaState::Within, Ticket::factory()->withinSla()->make()->computeSlaState());
        $this->assertSame(SlaState::Approaching, Ticket::factory()->approaching()->make()->computeSlaState());
        $this->assertSame(SlaState::Breached, Ticket::factory()->breached()->make()->computeSlaState());
    }

    public function test_finished_tickets_are_judged_by_when_they_were_resolved(): void
    {
        $due = now()->subHours(2);

        $late = Ticket::factory()->due($due, $due->copy()->subHour())->resolved(now())->make();
        $onTime = Ticket::factory()->due($due, $due->copy()->subHour())->resolved($due->copy()->subMinutes(10))->make();

        $this->assertSame(SlaState::Breached, $late->computeSlaState());
        $this->assertSame(SlaState::Met, $onTime->computeSlaState());
    }

    public function test_scheduler_command_dispatches_jobs_only_for_the_right_tickets(): void
    {
        Queue::fake();

        $approaching = Ticket::factory()->approaching()->create();
        $breached = Ticket::factory()->breached()->create();
        Ticket::factory()->withinSla()->create();
        Ticket::factory()->breached()->resolved()->create();                 // finished: ignored
        Ticket::factory()->breached()->create(['escalated_at' => now()]);     // already escalated

        $this->artisan('tickets:check-sla')->assertSuccessful();

        Queue::assertPushed(WarnTicketSla::class, 1);
        Queue::assertPushed(WarnTicketSla::class, fn ($job) => $job->ticketId === $approaching->id);
        Queue::assertPushed(EscalateTicketSla::class, 1);
        Queue::assertPushed(EscalateTicketSla::class, fn ($job) => $job->ticketId === $breached->id);
    }

    public function test_escalation_marks_logs_and_notifies_agent_and_admins(): void
    {
        Notification::fake();
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->breached()->assignedTo($agent)->create();

        $this->assertTrue(app(SlaEscalationService::class)->escalate($ticket->id));

        $ticket->refresh();
        $this->assertNotNull($ticket->escalated_at);
        $this->assertSame(1, $ticket->escalation_level);
        $this->assertTrue($ticket->activities()->where('type', 'escalated')->exists());
        Notification::assertSentTo($agent, SlaBreachedNotification::class);
        Notification::assertSentTo($admin, SlaBreachedNotification::class);
    }

    public function test_a_ticket_is_never_escalated_twice(): void
    {
        Notification::fake();
        $ticket = Ticket::factory()->breached()->create();
        User::factory()->admin()->create();
        $service = app(SlaEscalationService::class);

        $this->assertTrue($service->escalate($ticket->id));
        $this->assertFalse($service->escalate($ticket->id)); // e.g. an overlapping scheduler run

        $this->assertSame(1, $ticket->fresh()->escalation_level);
        $this->assertSame(1, $ticket->activities()->where('type', 'escalated')->count());
        Notification::assertSentTimes(SlaBreachedNotification::class, 1);
    }

    public function test_warning_is_sent_once_and_never_after_a_breach(): void
    {
        Notification::fake();
        $agent = User::factory()->agent()->create();
        $approaching = Ticket::factory()->approaching()->assignedTo($agent)->create();
        $service = app(SlaEscalationService::class);

        $this->assertTrue($service->warn($approaching->id));
        $this->assertFalse($service->warn($approaching->id));
        Notification::assertSentTo($agent, SlaApproachingNotification::class, 1);

        $breached = Ticket::factory()->breached()->create();
        $this->assertFalse($service->warn($breached->id), 'a breached ticket must not get a late "approaching" warning');
    }

    public function test_resolved_tickets_are_not_escalated(): void
    {
        $ticket = Ticket::factory()->breached()->resolved()->create();

        $this->assertFalse(app(SlaEscalationService::class)->escalate($ticket->id));
    }
}
