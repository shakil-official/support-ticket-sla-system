<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

/**
 * Record level authorization. Every controller action calls $this->authorize(...) or a
 * FormRequest::authorize() that ends up here, so the rules are enforced on the server no
 * matter what the UI shows.
 *
 *   admin    - everything
 *   agent    - sees the unassigned pool + own tickets; works only on tickets assigned to them
 *   customer - only tickets they created
 *
 * "handles" = the staff member who is allowed to work on the ticket (admin, or the assignee).
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // lists are narrowed with Ticket::visibleTo()
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return match (true) {
            $user->isAdmin() => true,
            $user->isAgent() => $ticket->assigned_agent_id === null || $ticket->assigned_agent_id === $user->id,
            default => $ticket->created_by === $user->id,
        };
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Staff may edit anything while they handle an active ticket; customers only their own untouched ticket. */
    public function update(User $user, Ticket $ticket): bool
    {
        if (! $ticket->status->isActive()) {
            return false;
        }

        return $this->handles($user, $ticket)
            || ($user->isCustomer() && $ticket->created_by === $user->id && $ticket->status === TicketStatus::Open);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /** An agent accepts a ticket from the unassigned pool. */
    public function claim(User $user, Ticket $ticket): bool
    {
        return $user->isAgent() && $ticket->isUnassigned() && $ticket->status->isActive();
    }

    /** Manual (re)assignment. */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() && $ticket->status->isActive();
    }

    /** Public reply. Customers reply to their own tickets (a reply to a Resolved ticket reopens it). */
    public function reply(User $user, Ticket $ticket): bool
    {
        if ($ticket->status === TicketStatus::Closed || ! $this->view($user, $ticket)) {
            return false;
        }

        return $user->isStaff()
            ? $this->handles($user, $ticket)
            : $ticket->created_by === $user->id;
    }

    /** Internal notes: staff only, and only on tickets they work on. */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->isStaff()
            && $ticket->status !== TicketStatus::Closed
            && $this->handles($user, $ticket);
    }

    /** The audit trail contains internal information (assignments, SLA, notes) - staff only. */
    public function viewActivity(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() && $this->view($user, $ticket);
    }

    /**
     * Status change. The state machine decides what is possible at all;
     * the role decides who may do it.
     */
    public function transition(User $user, Ticket $ticket, TicketStatus $to): bool
    {
        if (! $ticket->status->canTransitionTo($to)) {
            return false;
        }

        if ($this->handles($user, $ticket)) {
            return true;
        }

        // The requester may confirm (close) or reopen a ticket that staff marked Resolved.
        return $user->isCustomer()
            && $ticket->created_by === $user->id
            && $ticket->status === TicketStatus::Resolved
            && in_array($to, [TicketStatus::Closed, TicketStatus::Open], true);
    }

    private function handles(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin()
            || ($user->isAgent() && $ticket->assigned_agent_id === $user->id);
    }
}
