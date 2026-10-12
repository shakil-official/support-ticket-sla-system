<?php

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/** Tells the new assignee (unless they claimed it themselves) and the customer. */
class NotifyTicketAssigned implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $deleteWhenMissingModels = true;

    public function handle(TicketAssigned $event): void
    {
        $ticket = $event->ticket;

        if ($event->actor?->id !== $event->agent->id) {
            $event->agent->notify(new TicketAssignedNotification($ticket, $event->agent, toAgent: true));
        }

        User::query()->find($ticket->created_by)
            ?->notify(new TicketAssignedNotification($ticket, $event->agent, toAgent: false));
    }
}
