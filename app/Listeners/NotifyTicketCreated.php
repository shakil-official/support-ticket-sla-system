<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/** Confirmation to the person who opened the ticket. */
class NotifyTicketCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $deleteWhenMissingModels = true;

    public function handle(TicketCreated $event): void
    {
        User::query()->find($event->ticket->created_by)
            ?->notify(new TicketCreatedNotification($event->ticket));
    }
}
