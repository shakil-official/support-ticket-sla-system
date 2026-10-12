<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates TKT-2026-000001 style numbers without ever producing a duplicate.
 *
 * Why not MAX(id)+1 / COUNT(*)+1?  Two concurrent requests read the same value and
 * both write it. Instead we keep ONE counter row per year and increment it while
 * holding a row lock (SELECT ... FOR UPDATE). Concurrent creators queue up on that row,
 * so numbers are unique AND gap-free (a rolled back ticket gives its number back).
 * `tickets.ticket_number` additionally has a UNIQUE index as a last line of defence.
 *
 * Call it INSIDE the transaction that inserts the ticket; the lock is released on commit.
 */
class TicketNumberGenerator
{
    public function next(?CarbonInterface $at = null): string
    {
        return $this->reserve(1, $at)[0];
    }

    /**
     * Reserve a block of numbers with a single lock round-trip (used by the CSV importer).
     *
     * @return list<string>
     */
    public function reserve(int $count, ?CarbonInterface $at = null): array
    {
        if ($count < 1) {
            return [];
        }

        $year = ($at ?? now())->year;

        return DB::transaction(function () use ($count, $year): array {
            // Make sure the row exists (no-op when it does), then lock and bump it.
            DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

            $last = (int) DB::table('ticket_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_number');

            DB::table('ticket_sequences')->where('year', $year)->update(['last_number' => $last + $count]);

            $numbers = [];
            for ($n = $last + 1; $n <= $last + $count; $n++) {
                $numbers[] = $this->format($year, $n);
            }

            return $numbers;
        });
    }

    public function format(int $year, int $sequence): string
    {
        return sprintf(
            '%s-%d-%0'.config('tickets.number_padding', 6).'d',
            config('tickets.prefix', 'TKT'),
            $year,
            $sequence,
        );
    }
}
