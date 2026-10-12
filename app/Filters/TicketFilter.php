<?php

namespace App\Filters;

use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns the (validated) query string of the ticket list into SQL.
 * Every filter is optional and composes with the others; visibility (Ticket::visibleTo)
 * and the active/resolved tab are applied by the controller BEFORE this class runs, so a
 * filter can only ever narrow the result, never widen it.
 *
 * Index usage: status/priority/category/agent filters hit the composite indexes of
 * `tickets`; the keyword uses the FULLTEXT index on MySQL.
 */
class TicketFilter
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  bool  $closedView  true = "resolved/closed" tab (changes the meaning of the SLA filter)
     */
    public function apply(Builder $query, array $filters, User $user, bool $closedView): Builder
    {
        return $query
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $this->keyword($q, $term))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('tickets.status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $v) => $q->where('tickets.priority', $v))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('tickets.category_id', (int) $v))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $v) => $q->where('tickets.created_at', '>=', $this->day($v)->startOfDay()->utc()))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $v) => $q->where('tickets.created_at', '<=', $this->day($v)->endOfDay()->utc()))
            ->when($filters['sla'] ?? null, fn (Builder $q, string $v) => $this->sla($q, $v, $closedView))
            // The two filters below only make sense for staff (customers only ever see their own).
            ->when($user->isStaff() ? ($filters['agent'] ?? null) : null, fn (Builder $q, string $v) => $this->agent($q, $v))
            ->when($user->isStaff() ? ($filters['customer'] ?? null) : null, fn (Builder $q, string $v) => $this->customer($q, $v));
    }

    /** Ticket number prefix OR keyword in subject/description. */
    private function keyword(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('tickets.ticket_number', 'like', $this->escapeLike($term).'%');

            if ($boolean = $this->fullTextQuery($term)) {
                $q->orWhereRaw('MATCH(tickets.subject, tickets.description) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
            } else {
                $like = '%'.$this->escapeLike($term).'%';
                $q->orWhere('tickets.subject', 'like', $like)->orWhere('tickets.description', 'like', $like);
            }
        });
    }

    /**
     * FULLTEXT is used only when every word is long enough for the InnoDB index
     * (innodb_ft_min_token_size = 3); otherwise the safe LIKE fallback keeps results complete.
     * Each word becomes a required prefix match:  "vpn error" -> "+vpn* +error*".
     */
    private function fullTextQuery(string $term): ?string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }

        $words = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $term) ?? '', -1, PREG_SPLIT_NO_EMPTY);

        if ($words === [] || $words === false) {
            return null;
        }

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                return null;
            }
        }

        return implode(' ', array_map(fn (string $w) => '+'.$w.'*', $words));
    }

    private function sla(Builder $query, string $state, bool $closedView): Builder
    {
        if ($closedView) {
            // Finished tickets: was the deadline met?
            return match ($state) {
                'met' => $query->whereColumn('tickets.resolved_at', '<=', 'tickets.due_at'),
                'breached' => $query->whereColumn('tickets.resolved_at', '>', 'tickets.due_at'),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return match ($state) {
            'within' => $query->slaWithin(),
            'approaching' => $query->slaApproaching(),
            'breached' => $query->slaBreached(),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function agent(Builder $query, string $value): Builder
    {
        return $value === 'unassigned'
            ? $query->whereNull('tickets.assigned_agent_id')
            : $query->where('tickets.assigned_agent_id', (int) $value);
    }

    /** Customer by name or e-mail (partial match) via a sub-select on the indexed FK. */
    private function customer(Builder $query, string $value): Builder
    {
        $like = '%'.$this->escapeLike(trim($value)).'%';

        return $query->whereIn('tickets.created_by', function ($sub) use ($like) {
            $sub->select('users.id')->from('users')
                ->where('users.name', 'like', $like)
                ->orWhere('users.email', 'like', $like);
        });
    }

    /** Dates typed by the user are calendar days in the BUSINESS timezone. */
    private function day(string $date): Carbon
    {
        return Carbon::parse($date, config('tickets.timezone'));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
