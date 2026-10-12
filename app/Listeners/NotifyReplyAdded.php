<?php

namespace App\Listeners;

use App\Events\ReplyAdded;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketReplyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Agent replied    -> notify the customer.
 * Customer replied -> notify the assigned agent (or every admin while unassigned).
 * The author is never notified about their own reply.
 */
class NotifyReplyAdded implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $deleteWhenMissingModels = true;

    public function handle(ReplyAdded $event): void
    {
        $reply = TicketReply::query()->with('author:id,name,role')->find($event->reply->id);
        $ticket = $reply ? Ticket::query()->find($reply->ticket_id) : null;

        if ($reply === null || $ticket === null || $reply->is_internal) {
            return;
        }

        $byStaff = $reply->author->isStaff();

        $recipients = $byStaff
            ? User::query()->whereKey($ticket->created_by)->get()
            : ($ticket->assigned_agent_id
                ? User::query()->whereKey($ticket->assigned_agent_id)->get()
                : User::query()->admins()->get());

        $recipients = $recipients->reject(fn (User $u) => $u->id === $reply->user_id);

        Notification::send($recipients, new TicketReplyNotification($ticket, $reply, $reply->author->name, $byStaff));
    }
}
