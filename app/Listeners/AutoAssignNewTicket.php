<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Services\TicketAssignmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Runs in the background so ticket creation stays fast. If nobody can take the ticket,
 * admins are told that it sits in the unassigned queue.
 */
class AutoAssignNewTicket implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'default';

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public function __construct(private readonly TicketAssignmentService $assignment) {}

    public function handle(TicketCreated $event): void
    {
        if (! config('tickets.auto_assign')) {
            return;
        }

        $ticket = Ticket::query()->find($event->ticket->id);

        if ($ticket === null || ! $ticket->isUnassigned()) {
            return;
        }

        if ($this->assignment->autoAssign($ticket) === null && Ticket::query()->whereKey($ticket->id)->whereNull('assigned_agent_id')->exists()) {
            Notification::send(User::query()->admins()->get(), new TicketCreatedNotification($ticket, forStaff: true));
        }
    }
}
