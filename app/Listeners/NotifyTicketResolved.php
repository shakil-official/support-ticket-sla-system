<?php

namespace App\Listeners;

use App\Events\TicketResolved;
use App\Models\User;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyTicketResolved implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $deleteWhenMissingModels = true;

    public function handle(TicketResolved $event): void
    {
        User::query()->find($event->ticket->created_by)
            ?->notify(new TicketResolvedNotification($event->ticket));
    }
}
