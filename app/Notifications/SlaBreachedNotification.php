<?php

namespace App\Notifications;

class SlaBreachedNotification extends TicketNotification
{
    protected function title(): string
    {
        return 'SLA breached - ticket escalated';
    }

    protected function message(): string
    {
        return 'This ticket passed its SLA deadline and was escalated.';
    }
}
