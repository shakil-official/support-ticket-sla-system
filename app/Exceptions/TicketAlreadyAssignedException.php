<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when an agent tries to claim a ticket that somebody else already took. */
class TicketAlreadyAssignedException extends RuntimeException
{
    public function __construct(string $message = 'This ticket has already been assigned to another agent.')
    {
        parent::__construct($message);
    }
}
