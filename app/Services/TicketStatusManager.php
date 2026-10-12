<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidTicketTransitionException;
use App\Models\Ticket;
use App\Models\User;

/**
 * The only place that mutates `tickets.status`. It enforces the state machine
 * (TicketStatus::allowedTransitions), maintains resolved_at / closed_at and writes the
 * audit entry. It does NOT open a transaction or fire events: callers do, because they
 * usually change several things at once.
 */
class TicketStatusManager
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @throws InvalidTicketTransitionException */
    public function transition(Ticket $ticket, TicketStatus $to, ?User $actor = null, ?string $reason = null): bool
    {
        $from = $ticket->status;

        if ($from === $to) {
            return false;
        }

        if (! $from->canTransitionTo($to)) {
            throw InvalidTicketTransitionException::between($from, $to);
        }

        $ticket->status = $to;

        match ($to) {
            TicketStatus::Resolved => $ticket->resolved_at = now(),
            TicketStatus::Closed => $this->close($ticket),
            default => $this->reopen($ticket),
        };

        $ticket->save();

        $this->activity->log(
            $ticket,
            ActivityType::StatusChanged,
            "Status changed from {$from->label()} to {$to->label()}".($reason ? " ({$reason})" : ''),
            $actor,
            ['status' => $from->value],
            ['status' => $to->value],
        );

        return true;
    }

    private function close(Ticket $ticket): void
    {
        $ticket->resolved_at ??= now();
        $ticket->closed_at = now();
    }

    private function reopen(Ticket $ticket): void
    {
        // Moving back to an active status clears the "finished" timestamps.
        $ticket->resolved_at = null;
        $ticket->closed_at = null;
    }
}
