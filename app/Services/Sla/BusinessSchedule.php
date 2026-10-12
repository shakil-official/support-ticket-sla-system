<?php

namespace App\Services\Sla;

use App\Models\BusinessHour;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Immutable description of WHEN the support team works.
 * It knows nothing about tickets - it only answers "what is the working window of day X?".
 * Keeping it separate from SlaCalculator means the calculator can be unit tested with a
 * hand-built schedule, without a database.
 */
final class BusinessSchedule
{
    public const CACHE_KEY = 'sla.business_schedule';

    /**
     * @param  array<int, array{start: string, end: string}>  $days  working days only, keyed by
     *                                                                Carbon dayOfWeek (0 = Sunday)
     * @param  list<string>  $holidays  'Y-m-d' dates (in business timezone) that are never working days
     */
    public function __construct(
        private readonly array $days,
        private readonly array $holidays,
        private readonly string $timezone,
    ) {}

    /** The assessment's example: Sunday-Thursday, 09:00-18:00. */
    public static function default(string $timezone): self
    {
        $days = [];
        foreach ([0, 1, 2, 3, 4] as $dow) {
            $days[$dow] = ['start' => '09:00', 'end' => '18:00'];
        }

        return new self($days, [], $timezone);
    }

    /** Loads configuration from the database (cached; models flush the cache on change). */
    public static function fromDatabase(): self
    {
        $data = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $days = [];
            foreach (BusinessHour::query()->where('is_working', true)->get() as $hour) {
                $days[$hour->day_of_week] = [
                    'start' => substr((string) $hour->start_time, 0, 5),
                    'end' => substr((string) $hour->end_time, 0, 5),
                ];
            }

            $holidays = Holiday::query()->get()
                ->map(fn (Holiday $h) => $h->date->format('Y-m-d'))
                ->all();

            return ['days' => $days, 'holidays' => $holidays];
        });

        return new self($data['days'], $data['holidays'], config('tickets.timezone'));
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function hasWorkingDays(): bool
    {
        return $this->days !== [];
    }

    /**
     * Working window of the calendar day that contains $at (business timezone),
     * or null when that day is a weekend / holiday.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null  [opens, closes]
     */
    public function windowFor(CarbonInterface $at): ?array
    {
        $day = CarbonImmutable::createFromTimestamp($at->getTimestamp(), $this->timezone);

        if (! isset($this->days[$day->dayOfWeek])) {
            return null;
        }

        if (in_array($day->format('Y-m-d'), $this->holidays, true)) {
            return null;
        }

        [$startH, $startM] = array_map('intval', explode(':', $this->days[$day->dayOfWeek]['start']));
        [$endH, $endM] = array_map('intval', explode(':', $this->days[$day->dayOfWeek]['end']));

        return [
            $day->setTime($startH, $startM, 0),
            $day->setTime($endH, $endM, 0),
        ];
    }
}
