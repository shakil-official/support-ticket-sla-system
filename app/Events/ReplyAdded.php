<?php

namespace App\Events;

use App\Models\TicketReply;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired for PUBLIC replies only. Internal notes never notify anyone outside staff. */
class ReplyAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(public TicketReply $reply) {}
}
