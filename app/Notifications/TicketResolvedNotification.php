<?php

namespace App\Notifications;

class TicketResolvedNotification extends TicketNotification
{
    protected function title(): string
    {
        return 'Your ticket was resolved';
    }

    protected function message(): string
    {
        return 'Your ticket is marked as resolved. Reply to it if the problem persists, otherwise it will be closed automatically.';
    }
}
