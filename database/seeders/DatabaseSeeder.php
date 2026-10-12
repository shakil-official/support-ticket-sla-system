<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data (SLA policies, business hours, categories) is required in EVERY environment;
     * demo users and demo tickets are skipped automatically in production.
     * All seeders are idempotent (updateOrCreate), so re-running is safe.
     */
    public function run(): void
    {
        $this->call([
            SlaPolicySeeder::class,
            BusinessHourSeeder::class,
            CategorySeeder::class,
            UserSeeder::class,
            DemoTicketSeeder::class,
        ]);
    }
}
