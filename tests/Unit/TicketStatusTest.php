<?php

namespace Tests\Unit;

use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;

class TicketStatusTest extends TestCase
{
    public function test_closed_tickets_cannot_jump_back_to_open(): void
    {
        $this->assertFalse(TicketStatus::Closed->canTransitionTo(TicketStatus::Open));
    }

    public function test_the_normal_lifecycle_is_allowed(): void
    {
        $this->assertTrue(TicketStatus::Open->canTransitionTo(TicketStatus::InProgress));
        $this->assertTrue(TicketStatus::InProgress->canTransitionTo(TicketStatus::WaitingForCustomer));
        $this->assertTrue(TicketStatus::InProgress->canTransitionTo(TicketStatus::Resolved));
        $this->assertTrue(TicketStatus::Resolved->canTransitionTo(TicketStatus::Closed));
    }

    public function test_active_and_closed_values_do_not_overlap(): void
    {
        $this->assertSame([], array_intersect(TicketStatus::activeValues(), TicketStatus::closedValues()));
    }
}
