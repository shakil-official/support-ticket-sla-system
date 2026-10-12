<?php

namespace Tests\Concerns;

use App\Models\Category;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Support\Facades\Cache;

/** SLA policies + Sun-Thu 09-18 business hours + a "General" category: what every ticket needs. */
trait SeedsReferenceData
{
    protected Category $category;

    protected function seedReferenceData(): void
    {
        Cache::flush();
        $this->seed([SlaPolicySeeder::class, BusinessHourSeeder::class]);
        $this->category = Category::factory()->create(['name' => 'General', 'slug' => 'general']);
    }
}
