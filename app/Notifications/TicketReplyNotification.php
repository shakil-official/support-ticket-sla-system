<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/** "Agent replied" (sent to the customer) and "Customer replied" (sent to the agent / admins). */
class TicketReplyNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, private readonly TicketReply $reply, private readonly string $authorName, private readonly bool $byStaff)
    {
        // Hard guarantee: an internal note can never become a notification.
        abort_if($reply->is_internal, 500, 'Internal notes must never be notified.');

        parent::__construct($ticket);
    }

    protected function title(): string
    {
        return $this->byStaff ? 'An agent replied to your ticket' : 'The customer replied';
    }

    protected function message(): string
    {
        return ($this->byStaff ? 'Agent' : 'Customer')." {$this->authorName} replied.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->line('"'.Str::limit($this->reply->body, 300).'"');
    }
}
