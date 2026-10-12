<?php

namespace App\Enums;

enum SlaState: string
{
    case Within = 'within';
    case Approaching = 'approaching';
    case Breached = 'breached';
    case Met = 'met';

    public function label(): string
    {
        return match ($this) {
            self::Within => 'Within SLA',
            self::Approaching => 'Approaching breach',
            self::Breached => 'SLA breached',
            self::Met => 'SLA met',
        };
    }
}
