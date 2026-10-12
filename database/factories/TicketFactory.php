<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds ticket ROWS directly (no events, no notifications, no sequence table) so tests can
 * put a ticket into any state quickly. Real behaviour is tested through TicketService.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        $created = now();

        return [
            'ticket_number' => 'TKT-'.$created->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
            'category_id' => Category::factory(),
            'created_by' => User::factory(),
            'assigned_agent_id' => null,
            'sla_resolution_minutes' => 1440,
            'due_at' => $created->copy()->addDay(),
            'sla_warning_at' => $created->copy()->addHours(18),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function assignedTo(User $agent): static
    {
        return $this->state(fn () => ['assigned_agent_id' => $agent->id]);
    }

    public function createdBy(User $customer): static
    {
        return $this->state(fn () => ['created_by' => $customer->id]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn () => ['category_id' => $category->id]);
    }

    /** Deadline passed one hour ago (never warned / escalated yet). */
    public function breached(): static
    {
        return $this->due(now()->subHour(), now()->subHours(4));
    }

    /** Past the warning point, deadline in one hour. */
    public function approaching(): static
    {
        return $this->due(now()->addHour(), now()->subMinutes(10));
    }

    /** Plenty of time left. */
    public function withinSla(): static
    {
        return $this->due(now()->addHours(20), now()->addHours(15));
    }

    public function due(CarbonInterface $dueAt, CarbonInterface $warningAt): static
    {
        return $this->state(fn () => ['due_at' => $dueAt, 'sla_warning_at' => $warningAt]);
    }

    public function resolved(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => ['status' => TicketStatus::Resolved, 'resolved_at' => $at ?? now()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::Closed, 'resolved_at' => now(), 'closed_at' => now(),
        ]);
    }
}
