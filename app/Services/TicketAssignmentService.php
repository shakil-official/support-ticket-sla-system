<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Ticket;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Manual assignment, agent self-claim and automatic assignment.
 *
 * AUTO-ASSIGNMENT STRATEGY - "least workload, skill aware":
 *   1. candidates = agents that are AVAILABLE and skilled in the ticket's category
 *      (agent_category). If nobody is skilled, fall back to available agents that have
 *      no category restriction at all ("generalists").
 *   2. pick the candidate with the fewest ACTIVE tickets;
 *   3. tie-break: the agent who was assigned a ticket longest ago (never = first), then id.
 *   4. nobody available -> ticket stays in the unassigned pool and admins are notified.
 *
 * CONCURRENCY: two agents pressing "Accept" on the same ticket is solved with ONE atomic
 * statement:  UPDATE tickets SET assigned_agent_id = ? WHERE id = ? AND assigned_agent_id IS NULL
 * InnoDB takes a row lock for the UPDATE; the second transaction waits, then re-evaluates
 * the WHERE clause against the committed row, matches 0 rows and loses cleanly.
 * No application level "check then write" gap exists.
 */
class TicketAssignmentService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly TicketStatusManager $status,
    ) {}

    /** An agent accepts an unassigned ticket. @throws TicketAlreadyAssignedException */
    public function claim(Ticket $ticket, User $agent): Ticket
    {
        return $this->assignIfUnassigned($ticket, $agent, $agent, "Ticket claimed by {$agent->name}");
    }

    /**
     * Pick an agent automatically. Returns null when nobody could take it
     * (no candidate, or somebody claimed the ticket in the meantime).
     */
    public function autoAssign(Ticket $ticket): ?Ticket
    {
        $agent = $this->pickAgentFor($ticket);

        if ($agent === null) {
            $this->activity->log(
                $ticket, ActivityType::AssignmentFailed,
                'Auto-assignment found no available agent; ticket stays in the unassigned queue',
            );

            return null;
        }

        try {
            return $this->assignIfUnassigned(
                $ticket, $agent, null, "Auto-assigned to {$agent->name} (least active workload)",
            );
        } catch (TicketAlreadyAssignedException) {
            return null;
        }
    }

    /**
     * Manual (re)assignment by an administrator. Unlike a claim this may overwrite an
     * existing assignee, so it uses a locked read-modify-write inside a transaction.
     */
    public function assign(Ticket $ticket, User $agent, User $actor): Ticket
    {
        if (! $agent->isAgent()) {
            throw new DomainException('Tickets can only be assigned to support agents.');
        }

        $result = DB::transaction(function () use ($ticket, $agent, $actor): ?Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $locked->status->isActive()) {
                throw new DomainException('Resolved or closed tickets cannot be assigned.');
            }

            $previousId = $locked->assigned_agent_id;

            if ((int) $previousId === $agent->id) {
                return null; // nothing to do
            }

            $previous = $previousId ? User::query()->find($previousId) : null;
            $locked->assigned_agent_id = $agent->id;
            $locked->save();

            if ($locked->status === TicketStatus::Open) {
                $this->status->transition($locked, TicketStatus::InProgress, $actor);
            }

            $agent->forceFill(['last_assigned_at' => now()])->save();

            $this->activity->log(
                $locked,
                $previous ? ActivityType::Reassigned : ActivityType::Assigned,
                $previous
                    ? "Reassigned from {$previous->name} to {$agent->name} by {$actor->name}"
                    : "Assigned to {$agent->name} by {$actor->name}",
                $actor,
                ['assigned_agent_id' => $previousId],
                ['assigned_agent_id' => $agent->id],
            );

            return $locked;
        });

        if ($result === null) {
            return $ticket;
        }

        TicketAssigned::dispatch($result, $agent, $actor);

        return $result;
    }

    // ---------------------------------------------------------------------

    private function assignIfUnassigned(Ticket $ticket, User $agent, ?User $actor, string $description): Ticket
    {
        $assigned = DB::transaction(function () use ($ticket, $agent, $actor, $description): ?Ticket {
            // The atomic compare-and-set. Exactly one concurrent caller gets affected = 1.
            $affected = Ticket::query()
                ->whereKey($ticket->id)
                ->whereNull('assigned_agent_id')
                ->whereIn('status', TicketStatus::activeValues())
                ->update(['assigned_agent_id' => $agent->id]);

            if ($affected === 0) {
                return null;
            }

            // We now hold the row lock until commit, so this read/transition is race free.
            $locked = Ticket::query()->findOrFail($ticket->id);

            if ($locked->status === TicketStatus::Open) {
                $this->status->transition($locked, TicketStatus::InProgress, $actor);
            }

            $agent->forceFill(['last_assigned_at' => now()])->save();

            $this->activity->log(
                $locked, ActivityType::Assigned, $description, $actor,
                ['assigned_agent_id' => null], ['assigned_agent_id' => $agent->id],
            );

            return $locked;
        });

        if ($assigned === null) {
            throw new TicketAlreadyAssignedException;
        }

        TicketAssigned::dispatch($assigned, $agent, $actor);

        return $assigned;
    }

    private function pickAgentFor(Ticket $ticket): ?User
    {
        $base = fn () => User::query()
            ->agents()
            ->available()
            ->withCount(['assignedTickets as active_tickets_count' => fn ($q) => $q->active()])
            ->orderBy('active_tickets_count')
            ->orderByRaw('last_assigned_at IS NULL DESC')   // never assigned first
            ->orderBy('last_assigned_at')                   // then the one idle the longest
            ->orderBy('users.id');

        $skilled = $base()
            ->whereHas('categories', fn ($q) => $q->whereKey($ticket->category_id))
            ->first();

        return $skilled ?? $base()->whereDoesntHave('categories')->first();
    }
}
