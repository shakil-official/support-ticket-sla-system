<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class SearchAndDashboardTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->admin = User::factory()->admin()->create();
    }

    private function ids(array $query): array
    {
        $response = $this->actingAs($this->admin)->get(route('tickets.index', $query))->assertOk();

        return $response->viewData('tickets')->pluck('id')->all();
    }

    public function test_filters_combine_and_only_narrow_the_result(): void
    {
        $agent = User::factory()->agent()->create();
        $billing = Category::factory()->create();
        $target = Ticket::factory()->priority(TicketPriority::High)->assignedTo($agent)->inCategory($billing)
            ->create(['subject' => 'VPN certificate expired']);
        Ticket::factory()->priority(TicketPriority::Low)->create(['subject' => 'VPN slow']);
        Ticket::factory()->priority(TicketPriority::High)->create(['subject' => 'Printer jam']);

        $this->assertCount(3, $this->ids([]));
        $this->assertSame([$target->id], $this->ids(['q' => 'vpn', 'priority' => 'high']));
        $this->assertSame([$target->id], $this->ids(['agent' => $agent->id]));
        $this->assertSame([$target->id], $this->ids(['category_id' => $billing->id]));
        $this->assertCount(2, $this->ids(['agent' => 'unassigned']));
    }

    public function test_search_by_ticket_number_and_customer(): void
    {
        $customer = User::factory()->create(['name' => 'Zed Zebra', 'email' => 'zed@zoo.test']);
        $ticket = Ticket::factory()->createdBy($customer)->create(['ticket_number' => 'TKT-2026-004242']);
        Ticket::factory()->create();

        $this->assertSame([$ticket->id], $this->ids(['q' => 'TKT-2026-004242']));
        $this->assertSame([$ticket->id], $this->ids(['customer' => 'zoo.test']));
    }

    public function test_sla_status_filter(): void
    {
        $breached = Ticket::factory()->breached()->create();
        $approaching = Ticket::factory()->approaching()->create();
        $within = Ticket::factory()->withinSla()->create();

        $this->assertSame([$breached->id], $this->ids(['sla' => 'breached']));
        $this->assertSame([$approaching->id], $this->ids(['sla' => 'approaching']));
        $this->assertSame([$within->id], $this->ids(['sla' => 'within']));
    }

    public function test_results_are_paginated_and_the_query_count_does_not_grow_with_the_rows(): void
    {
        Ticket::factory()->count(45)->create();

        $this->assertCount(20, $this->ids([]));

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get(route('tickets.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        $with20 = $count();
        Ticket::factory()->count(30)->create();

        $this->assertSame($with20, $count(), 'N+1: the number of queries depends on the number of rows');
    }

    public function test_date_range_filter(): void
    {
        $old = Ticket::factory()->create(['created_at' => now()->subDays(10)]);
        $new = Ticket::factory()->create(['created_at' => now()]);

        $this->assertSame([$new->id], $this->ids(['date_from' => now()->subDay()->toDateString()]));
        $this->assertSame([$old->id], $this->ids(['date_to' => now()->subDays(5)->toDateString()]));
    }

    // ---- dashboard --------------------------------------------------------------------

    public function test_dashboard_numbers(): void
    {
        $agent = User::factory()->agent()->create();
        Ticket::factory()->create();
        Ticket::factory()->priority(TicketPriority::Critical)->assignedTo($agent)->breached()->create();
        Ticket::factory()->approaching()->create();
        Ticket::factory()->resolved()->create();

        Cache::flush();
        $stats = app(DashboardService::class)->stats($this->admin);

        $this->assertSame(3, $stats['open_total']);
        $this->assertSame(2, $stats['unassigned']);
        $this->assertSame(1, $stats['critical']);
        $this->assertSame(1, $stats['breached']);
        $this->assertSame(1, $stats['approaching']);
        $this->assertSame(1, $stats['resolved']);
        $this->assertSame(1, $stats['by_priority']['critical']);
        $this->assertSame([['name' => $agent->name, 'total' => 1]], $stats['by_agent']);
    }

    public function test_dashboard_is_scoped_to_what_the_user_may_see(): void
    {
        $customer = User::factory()->create();
        Ticket::factory()->createdBy($customer)->create();
        Ticket::factory()->count(4)->create();

        Cache::flush();

        $this->assertSame(1, app(DashboardService::class)->stats($customer)['open_total']);
        $this->actingAs($customer)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
    }
}
