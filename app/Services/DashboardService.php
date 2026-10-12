<?php

namespace App\Services;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard numbers with the minimum number of queries:
 *  - ALL scalar counters come from ONE query using conditional aggregation
 *    (SUM(CASE WHEN ...)) instead of 7 separate COUNT(*) queries;
 *  - "by priority" and "by agent" are one GROUP BY each (agent names via JOIN, no N+1);
 *  - results are cached for 60 s, keyed per audience (admin: global, agent/customer: per user),
 *    and every query is scoped with Ticket::visibleTo() so nobody sees foreign data.
 */
class DashboardService
{
    public const TTL_SECONDS = 60;

    /** @return array<string, mixed> */
    public function stats(User $user): array
    {
        $key = 'dashboard.'.($user->isAdmin() ? 'admin' : $user->role->value.'.'.$user->id);

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->build($user));
    }

    private function build(User $user): array
    {
        $now = now();
        $active = "'".implode("','", TicketStatus::activeValues())."'";
        $closed = "'".implode("','", TicketStatus::closedValues())."'";

        $counters = Ticket::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN tickets.status IN ({$active}) THEN 1 ELSE 0 END), 0) AS open_total,
                COALESCE(SUM(CASE WHEN tickets.status IN ({$active}) AND tickets.assigned_agent_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned,
                COALESCE(SUM(CASE WHEN tickets.status IN ({$active}) AND tickets.priority = ? THEN 1 ELSE 0 END), 0) AS critical,
                COALESCE(SUM(CASE WHEN tickets.status IN ({$active}) AND tickets.due_at < ? THEN 1 ELSE 0 END), 0) AS breached,
                COALESCE(SUM(CASE WHEN tickets.status IN ({$active}) AND tickets.due_at >= ? AND tickets.sla_warning_at <= ? THEN 1 ELSE 0 END), 0) AS approaching,
                COALESCE(SUM(CASE WHEN tickets.status IN ({$closed}) THEN 1 ELSE 0 END), 0) AS resolved
            ", [TicketPriority::Critical->value, $now, $now, $now])
            ->first();

        $byPriority = Ticket::query()
            ->visibleTo($user)
            ->active()
            ->toBase()
            ->select('tickets.priority', DB::raw('COUNT(*) AS total'))
            ->groupBy('tickets.priority')
            ->pluck('total', 'priority');

        $byAgent = $user->isCustomer()
            ? collect()
            : Ticket::query()
                ->visibleTo($user)
                ->active()
                ->toBase()
                ->join('users', 'users.id', '=', 'tickets.assigned_agent_id')
                ->select('users.name', DB::raw('COUNT(*) AS total'))
                ->groupBy('users.id', 'users.name')
                ->orderByDesc('total')
                ->get();

        return [
            'open_total' => (int) $counters->open_total,
            'unassigned' => (int) $counters->unassigned,
            'critical' => (int) $counters->critical,
            'breached' => (int) $counters->breached,
            'approaching' => (int) $counters->approaching,
            'resolved' => (int) $counters->resolved,
            'by_priority' => collect(TicketPriority::cases())
                ->mapWithKeys(fn (TicketPriority $p) => [$p->value => (int) ($byPriority[$p->value] ?? 0)])
                ->all(),
            'by_agent' => $byAgent->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total])->all(),
        ];
    }
}
