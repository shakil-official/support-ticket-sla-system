<?php

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

/**
 * The assessment's example table. Values are BUSINESS minutes and editable in Admin > Settings.
 * firstOrCreate: re-seeding (every container start) never overwrites what an admin changed.
 */
class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        $hours = [
            TicketPriority::Low->value => 48,
            TicketPriority::Medium->value => 24,
            TicketPriority::High->value => 8,
            TicketPriority::Critical->value => 2,
        ];

        foreach ($hours as $priority => $h) {
            SlaPolicy::query()->firstOrCreate(
                ['priority' => $priority],
                ['resolution_minutes' => $h * 60, 'warning_threshold_percent' => 75],
            );
        }
    }
}
