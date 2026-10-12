<?php

namespace App\Services\Sla;

use App\Enums\ActivityType;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Performs the escalation work for ONE ticket. Both methods are idempotent: they start
 * with an atomic conditional UPDATE (`... WHERE warning_sent_at IS NULL`) that only one
 * worker can win, so overlapping scheduler runs, retried jobs or duplicate dispatches can
 * never warn / escalate the same ticket twice.
 */
class SlaEscalationService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** Approaching breach: warn the agent (or admins when unassigned). */
    public function warn(int $ticketId): bool
    {
        $won = DB::transaction(function () use ($ticketId): bool {
            $claimed = Ticket::query()
                ->whereKey($ticketId)
                ->active()
                ->whereNull('warning_sent_at')
                ->where('due_at', '>', now())
                ->update(['warning_sent_at' => now()]);

            if ($claimed === 0) {
                return false;
            }

            $this->activity->log(
                $ticketId, ActivityType::SlaWarning,
                'SLA warning: the ticket is approaching its resolution deadline',
            );

            return true;
        });

        if (! $won) {
            return false;
        }

        $ticket = Ticket::query()->find($ticketId);
        if ($ticket === null) {
            return true;
        }

        Notification::send($this->recipients($ticket), new SlaApproachingNotification($ticket));

        return true;
    }

    /** Breached: flag, bump the level, write history, notify agent + admins. */
    public function escalate(int $ticketId): bool
    {
        $won = DB::transaction(function () use ($ticketId): bool {
            $claimed = Ticket::query()
                ->whereKey($ticketId)
                ->active()
                ->whereNull('escalated_at')
                ->where('due_at', '<', now())
                ->update([
                    'escalated_at' => now(),
                    'escalation_level' => DB::raw('escalation_level + 1'),
                ]);

            if ($claimed === 0) {
                return false;
            }

            // A breach implies the warning phase is over: never send a late "approaching" mail.
            Ticket::query()->whereKey($ticketId)->whereNull('warning_sent_at')->update(['warning_sent_at' => now()]);

            $level = (int) Ticket::query()->whereKey($ticketId)->value('escalation_level');

            $this->activity->log(
                $ticketId, ActivityType::Escalated,
                "Ticket escalated (level {$level}): SLA breached",
                null, null, ['escalation_level' => $level],
            );

            return true;
        });

        if (! $won) {
            return false;
        }

        $ticket = Ticket::query()->find($ticketId);
        if ($ticket === null) {
            return true;
        }

        Notification::send($this->recipients($ticket, includeAdmins: true), new SlaBreachedNotification($ticket));

        return true;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function recipients(Ticket $ticket, bool $includeAdmins = false)
    {
        $users = collect();

        if ($ticket->assigned_agent_id) {
            $users->push(User::query()->find($ticket->assigned_agent_id));
        }

        if ($includeAdmins || $ticket->assigned_agent_id === null) {
            $users = $users->merge(User::query()->admins()->get());
        }

        return $users->filter()->unique('id')->values();
    }
}
