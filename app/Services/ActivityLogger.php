<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;

/**
 * Writes the audit trail. It is called from inside the same DB transaction as the change
 * it describes, so a ticket can never change without its history entry (and vice versa).
 */
class ActivityLogger
{
    public function log(
        Ticket|int $ticket,
        ActivityType $type,
        string $description,
        ?User $actor = null,
        ?array $old = null,
        ?array $new = null,
    ): TicketActivity {
        return TicketActivity::create([
            'ticket_id' => $ticket instanceof Ticket ? $ticket->id : $ticket,
            'user_id' => $actor?->id,          // null = performed by the system
            'type' => $type,
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'created_at' => now(),             // explicit so it follows Carbon::setTestNow()
        ]);
    }
}
