<?php

namespace App\Services\Sla;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Adds BUSINESS minutes to a start time.
 *
 * The algorithm walks forward day by day, "spending" the remaining minutes only inside
 * working windows:
 *
 *   cursor = start
 *   repeat for each calendar day:
 *       window = business window of that day        (none on weekends / holidays)
 *       if cursor is before opening -> jump to opening
 *       if cursor is inside the window:
 *            if remaining fits before closing -> done: cursor + remaining
 *            otherwise spend what is left of today and continue
 *       cursor = start of next day
 *
 * Example (Sun-Thu 09:00-18:00): Critical = 120 min, created Thursday 17:00
 *   Thu: 17:00 -> 18:00 spends 60, 60 remain  | Fri, Sat: closed
 *   Sun: opens 09:00, +60  => Sunday 10:00     (NOT Thursday 19:00)
 */
final class SlaCalculator
{
    /** Safety valve against an empty schedule (would otherwise loop forever). */
    private const MAX_DAYS_LOOKAHEAD = 800;

    /**
     * @param  string  $outputTimezone  returned instants are converted to this zone
     *                                  (the app/storage timezone, UTC) because Eloquent writes a
     *                                  Carbon value using its own timezone, without converting.
     */
    public function __construct(
        private readonly BusinessSchedule $schedule,
        private readonly string $outputTimezone = 'UTC',
    ) {}

    public function addBusinessMinutes(CarbonInterface $start, int $minutes): CarbonImmutable
    {
        if (! $this->schedule->hasWorkingDays()) {
            throw new LogicException('Cannot calculate an SLA deadline: no business days are configured.');
        }

        $cursor = CarbonImmutable::createFromTimestamp($start->getTimestamp(), $this->schedule->timezone());
        $remaining = max(0, $minutes) * 60; // work in seconds, integer maths only

        for ($i = 0; $i < self::MAX_DAYS_LOOKAHEAD; $i++) {
            $window = $this->schedule->windowFor($cursor);

            if ($window !== null) {
                [$opens, $closes] = $window;

                if ($cursor->lessThan($opens)) {
                    $cursor = $opens;               // created before opening time
                }

                if ($cursor->lessThan($closes)) {   // still inside today's window
                    $available = $closes->getTimestamp() - $cursor->getTimestamp();

                    if ($remaining <= $available) {
                        return $cursor->addSeconds($remaining)->setTimezone($this->outputTimezone);
                    }

                    $remaining -= $available;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new LogicException('Cannot calculate an SLA deadline: no working day found in range.');
    }
}
