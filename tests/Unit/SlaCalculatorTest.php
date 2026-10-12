<?php

namespace Tests\Unit;

use App\Services\Sla\BusinessSchedule;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test: no database, no framework. The schedule is built by hand
 * (Sun-Thu 09:00-18:00, Asia/Dhaka = UTC+6, no DST) which is exactly why
 * BusinessSchedule and SlaCalculator are separate classes.
 *
 * Reference calendar: 2026-10-08 is a Thursday, 2026-10-09 Friday, 2026-10-11 Sunday.
 */
class SlaCalculatorTest extends TestCase
{
    private const TZ = 'Asia/Dhaka';

    private function calc(?BusinessSchedule $schedule = null): SlaCalculator
    {
        return new SlaCalculator($schedule ?? BusinessSchedule::default(self::TZ), 'UTC');
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, self::TZ);
    }

    private function local(CarbonImmutable $utc): string
    {
        return $utc->setTimezone(self::TZ)->format('Y-m-d H:i');
    }

    public function test_assessment_example_critical_ticket_on_thursday_5pm_is_due_sunday_10am(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-08 17:00'), 120);

        $this->assertSame('2026-10-11 10:00', $this->local($due));
    }

    public function test_deadline_inside_the_same_working_day(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-08 10:00'), 120);

        $this->assertSame('2026-10-08 12:00', $this->local($due));
    }

    public function test_ticket_created_before_opening_time_starts_at_opening(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-08 07:30'), 60);

        $this->assertSame('2026-10-08 10:00', $this->local($due));
    }

    public function test_ticket_created_after_closing_time_starts_next_working_day(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-08 20:00'), 60);

        $this->assertSame('2026-10-11 10:00', $this->local($due)); // Fri + Sat are closed
    }

    public function test_ticket_created_on_a_weekend_starts_on_sunday_morning(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-09 12:00'), 30); // Friday

        $this->assertSame('2026-10-11 09:30', $this->local($due));
    }

    public function test_deadline_exactly_at_closing_time_stays_on_the_same_day(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-08 16:00'), 120);

        $this->assertSame('2026-10-08 18:00', $this->local($due));
    }

    public function test_exactly_one_full_working_day_of_minutes_ends_at_closing(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-11 09:00'), 9 * 60);

        $this->assertSame('2026-10-11 18:00', $this->local($due));
    }

    public function test_48_hour_low_priority_sla_spans_several_working_days(): void
    {
        // 48 h / 9 h per day = 5 days + 3 h. Sun 09:00 -> Sun,Mon,Tue,Wed,Thu (45 h) -> Sun next week +3 h.
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-11 09:00'), 48 * 60);

        $this->assertSame('2026-10-18 12:00', $this->local($due));
    }

    public function test_zero_minutes_returns_the_next_working_instant(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-09 12:00'), 0);

        $this->assertSame('2026-10-11 09:00', $this->local($due));
    }

    public function test_holidays_are_skipped(): void
    {
        $schedule = new BusinessSchedule(
            days: array_fill_keys([0, 1, 2, 3, 4], ['start' => '09:00', 'end' => '18:00']),
            holidays: ['2026-10-11'],   // the Sunday after the weekend is a holiday
            timezone: self::TZ,
        );

        $due = $this->calc(schedule: $schedule)->addBusinessMinutes($this->at('2026-10-08 17:00'), 120);

        $this->assertSame('2026-10-12 10:00', $this->local($due)); // Monday
    }

    public function test_result_is_returned_in_the_output_timezone(): void
    {
        $due = $this->calc()->addBusinessMinutes($this->at('2026-10-08 10:00'), 60);

        $this->assertSame('UTC', $due->getTimezone()->getName());
        $this->assertSame('2026-10-08 05:00', $due->format('Y-m-d H:i')); // 11:00 Dhaka = 05:00 UTC
    }

    public function test_a_schedule_without_working_days_fails_loudly(): void
    {
        $this->expectException(LogicException::class);

        $this->calc(schedule: new BusinessSchedule([], [], self::TZ))
            ->addBusinessMinutes($this->at('2026-10-08 10:00'), 60);
    }
}
