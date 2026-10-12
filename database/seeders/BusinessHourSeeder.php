<?php

namespace Database\Seeders;

use App\Models\BusinessHour;
use Illuminate\Database\Seeder;

/**
 * Sunday-Thursday, 09:00-18:00 (0 = Sunday ... 6 = Saturday), as in the assessment example.
 * firstOrCreate: never overwrites hours an admin edited.
 */
class BusinessHourSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(0, 6) as $day) {
            BusinessHour::query()->firstOrCreate(['day_of_week' => $day], [
                'is_working' => $day <= 4,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
            ]);
        }
    }
}
