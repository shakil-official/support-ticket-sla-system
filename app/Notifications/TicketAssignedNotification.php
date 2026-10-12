<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

class TicketAssignedNotification extends TicketNotification
{
    /** @param  bool  $toAgent  true: addressed to the new assignee, false: to the customer */
    public function __construct(Ticket $ticket, private readonly User $agent, private readonly bool $toAgent)
    {
        parent::__construct($ticket);
    }

    protected function title(): string
    {
        return $this->toAgent ? 'A ticket was assigned to you' : 'Your ticket has an agent';
    }

    protected function message(): string
    {
        return $this->toAgent
            ? 'You are now responsible for this ticket.'
            : "{$this->agent->name} is now working on your ticket.";
    }
}
