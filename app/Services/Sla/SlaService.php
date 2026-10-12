<?php

namespace App\Services\Sla;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Single entry point for "what is the SLA of a ticket of priority X created at T?".
 * Rules live in the `sla_policies` table; nothing else in the app hard-codes a number.
 */
class SlaService
{
    public const CACHE_KEY = 'sla.policies';

    public function __construct(private readonly SlaCalculator $calculator) {}

    /** @return array<string, array{minutes: int, warning_percent: int}> keyed by priority value */
    public function policies(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return SlaPolicy::query()->get()
                ->mapWithKeys(fn (SlaPolicy $p) => [
                    $p->priority->value => [
                        'minutes' => $p->resolution_minutes,
                        'warning_percent' => $p->warning_threshold_percent,
                    ],
                ])
                ->all();
        });
    }

    /** @return array{minutes: int, warning_percent: int} */
    public function policyFor(TicketPriority $priority): array
    {
        return $this->policies()[$priority->value]
            ?? throw new RuntimeException("No SLA policy configured for priority [{$priority->value}]. Run the seeders.");
    }

    /**
     * @return array{sla_resolution_minutes: int, due_at: CarbonInterface, sla_warning_at: CarbonInterface}
     */
    public function calculate(TicketPriority $priority, CarbonInterface $from): array
    {
        $policy = $this->policyFor($priority);
        $warningMinutes = (int) floor($policy['minutes'] * $policy['warning_percent'] / 100);

        return [
            'sla_resolution_minutes' => $policy['minutes'],
            // The warning point is measured in business time too (75% of the *working* minutes).
            'due_at' => $this->calculator->addBusinessMinutes($from, $policy['minutes']),
            'sla_warning_at' => $this->calculator->addBusinessMinutes($from, $warningMinutes),
        ];
    }

    /** (Re)computes the SLA columns from the ticket's own created_at. Does not save. */
    public function applyTo(Ticket $ticket): Ticket
    {
        return $ticket->forceFill($this->calculate($ticket->priority, $ticket->created_at));
    }
}
