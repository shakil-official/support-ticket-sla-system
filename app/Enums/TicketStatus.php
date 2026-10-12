<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::WaitingForCustomer => 'Waiting for Customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** "Active" = still needs work, so the SLA clock matters. */
    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    public function isClosedState(): bool
    {
        return ! $this->isActive();
    }

    /** @return list<self> */
    public static function activeCases(): array
    {
        return [self::Open, self::InProgress, self::WaitingForCustomer];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::activeCases());
    }

    /** @return list<string> */
    public static function closedValues(): array
    {
        return [self::Resolved->value, self::Closed->value];
    }

    /**
     * The state machine. Closed is terminal; a Resolved ticket can be reopened.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::WaitingForCustomer, self::Resolved],
            self::InProgress => [self::WaitingForCustomer, self::Resolved],
            self::WaitingForCustomer => [self::InProgress, self::Resolved],
            self::Resolved => [self::Open, self::InProgress, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
