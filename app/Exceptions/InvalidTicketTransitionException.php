<?php

namespace App\Exceptions;

use App\Enums\TicketStatus;
use RuntimeException;

class InvalidTicketTransitionException extends RuntimeException
{
    public static function between(TicketStatus $from, TicketStatus $to): self
    {
        return new self("A ticket cannot move from \"{$from->label()}\" to \"{$to->label()}\".");
    }
}
