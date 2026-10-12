<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Services\Sla\SlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/** "Changing SLA rules must not need code changes": prove the rules really live in the database. */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_the_seeded_policies_match_the_assessment_example(): void
    {
        $sla = app(SlaService::class);

        $this->assertSame(48 * 60, $sla->policyFor(TicketPriority::Low)['minutes']);
        $this->assertSame(24 * 60, $sla->policyFor(TicketPriority::Medium)['minutes']);
        $this->assertSame(8 * 60, $sla->policyFor(TicketPriority::High)['minutes']);
        $this->assertSame(2 * 60, $sla->policyFor(TicketPriority::Critical)['minutes']);
    }

    public function test_admin_can_change_an_sla_rule_and_it_takes_effect_immediately(): void
    {
        $admin = User::factory()->admin()->create();
        $policies = [];
        foreach (TicketPriority::values() as $p) {
            $policies[$p] = ['resolution_minutes' => 60, 'warning_threshold_percent' => 50];
        }
        $policies['high']['resolution_minutes'] = 360; // "High is now 6 hours"

        $this->actingAs($admin)->put(route('admin.settings.sla'), ['policies' => $policies])->assertSessionHasNoErrors();

        $this->assertSame(360, app(SlaService::class)->policyFor(TicketPriority::High)['minutes']); // cache was flushed
        $this->assertSame(360, SlaPolicy::query()->where('priority', 'high')->value('resolution_minutes'));
    }

    public function test_sla_values_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.settings.sla'), ['policies' => ['low' => ['resolution_minutes' => 0, 'warning_threshold_percent' => 150]]])
            ->assertSessionHasErrors();
    }

    public function test_business_hours_need_at_least_one_working_day(): void
    {
        $admin = User::factory()->admin()->create();
        $days = [];
        foreach (range(0, 6) as $d) {
            $days[$d] = ['is_working' => 0, 'start_time' => '09:00', 'end_time' => '18:00'];
        }

        $this->actingAs($admin)->put(route('admin.settings.hours'), ['days' => $days])->assertSessionHasErrors('days');
    }

    public function test_holidays_can_be_added_and_removed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.settings.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
            ->assertSessionHasNoErrors();
        $holiday = Holiday::query()->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.settings.holidays.destroy', $holiday))->assertRedirect();
        $this->assertSame(0, Holiday::query()->count());
    }
}
