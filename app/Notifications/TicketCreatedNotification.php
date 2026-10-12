<?php

namespace App\Notifications;

use App\Models\Ticket;

class TicketCreatedNotification extends TicketNotification
{
    /** $forStaff = true -> sent to admins because auto-assignment found nobody. */
    public function __construct(Ticket $ticket, private readonly bool $forStaff = false)
    {
        parent::__construct($ticket);
    }

    protected function title(): string
    {
        return $this->forStaff ? 'New ticket needs an agent' : 'We received your ticket';
    }

    protected function message(): string
    {
        return $this->forStaff
            ? 'A new ticket could not be auto-assigned and is waiting in the unassigned queue.'
            : 'Thanks for contacting us. Your ticket was created and our team will get back to you.';
    }
}
