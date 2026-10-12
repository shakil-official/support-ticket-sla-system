<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketNumberGenerator;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class TicketNumberTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_numbers_are_sequential_and_formatted(): void
    {
        $gen = app(TicketNumberGenerator::class);
        $at = Carbon::parse('2026-03-01 10:00:00');

        $this->assertSame('TKT-2026-000001', $gen->next($at));
        $this->assertSame('TKT-2026-000002', $gen->next($at));
    }

    public function test_counter_restarts_every_year(): void
    {
        $gen = app(TicketNumberGenerator::class);

        $gen->next(Carbon::parse('2026-12-31 10:00:00'));
        $gen->next(Carbon::parse('2026-12-31 11:00:00'));

        $this->assertSame('TKT-2027-000001', $gen->next(Carbon::parse('2027-01-01 00:10:00')));
    }

    public function test_format_grows_beyond_the_padding_without_breaking(): void
    {
        $this->assertSame('TKT-2026-1000000', app(TicketNumberGenerator::class)->format(2026, 1_000_000));
    }

    public function test_reserve_hands_out_a_contiguous_block(): void
    {
        $gen = app(TicketNumberGenerator::class);
        $numbers = $gen->reserve(3, Carbon::parse('2026-05-05'));

        $this->assertSame(['TKT-2026-000001', 'TKT-2026-000002', 'TKT-2026-000003'], array_values($numbers));
        $this->assertSame('TKT-2026-000004', $gen->next(Carbon::parse('2026-05-06')));
    }

    public function test_created_tickets_get_unique_numbers(): void
    {
        $customer = User::factory()->create();

        $a = app(TicketService::class)->create($customer, $this->payload());
        $b = app(TicketService::class)->create($customer, $this->payload());

        $this->assertNotSame($a->ticket_number, $b->ticket_number);
        $this->assertSame(2, Ticket::query()->distinct()->count('ticket_number'));
    }

    private function payload(): array
    {
        return ['subject' => 'S', 'description' => 'D', 'priority' => 'medium', 'category_id' => $this->category->id];
    }
}
