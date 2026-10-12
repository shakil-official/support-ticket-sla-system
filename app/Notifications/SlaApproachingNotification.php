<?php

namespace App\Notifications;

class SlaApproachingNotification extends TicketNotification
{
    protected function title(): string
    {
        return 'SLA approaching breach';
    }

    protected function message(): string
    {
        return 'This ticket has used most of its resolution time. Please resolve it before the SLA deadline.';
    }
}
