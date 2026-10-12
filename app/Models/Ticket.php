<?php

namespace App\Models;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['subject', 'description', 'priority', 'category_id'];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'sla_resolution_minutes' => 'integer',
            'due_at' => 'datetime',
            'sla_warning_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'warning_sent_at' => 'datetime',
            'escalated_at' => 'datetime',
            'escalation_level' => 'integer',
        ];
    }

    /** URLs look like /tickets/TKT-2026-000001 - readable, and ids are not enumerable. */
    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    // ---- Relations ---------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    // ---- Scopes ------------------------------------------------------------
    // Columns are always table-qualified so the scopes stay safe inside joins.

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('tickets.status', TicketStatus::activeValues());
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('tickets.status', TicketStatus::closedValues());
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('tickets.assigned_agent_id');
    }

    /**
     * Row level visibility. Applied to every list/count query so a user can never even
     * *select* a ticket they are not allowed to see (Policies guard single records).
     *
     *  admin    -> everything
     *  agent    -> tickets assigned to them + the unassigned pool (to claim from)
     *  customer -> only tickets they created
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isAgent()) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('tickets.assigned_agent_id', $user->id)
                    ->orWhereNull('tickets.assigned_agent_id');
            });
        }

        return $query->where('tickets.created_by', $user->id);
    }

    /** Active ticket whose due time has passed. Uses index (status, due_at). */
    public function scopeSlaBreached(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->active()->where('tickets.due_at', '<', $now ?? now());
    }

    /** Active ticket past its warning threshold but not yet due. */
    public function scopeSlaApproaching(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->active()
            ->where('tickets.due_at', '>=', $now)
            ->where('tickets.sla_warning_at', '<=', $now);
    }

    public function scopeSlaWithin(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->active()
            ->where('tickets.due_at', '>=', $now)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('tickets.sla_warning_at')
                    ->orWhere('tickets.sla_warning_at', '>', $now);
            });
    }

    // ---- SLA state (computed, never stored) --------------------------------
    // Storing "breached" in a column would go stale between scheduler runs. Instead the
    // state is derived from (status, due_at, sla_warning_at, resolved_at) on demand, and
    // the same predicates exist as query scopes above for filtering/counting in SQL.

    public function computeSlaState(?CarbonInterface $now = null): ?SlaState
    {
        if ($this->due_at === null) {
            return null;
        }

        $now ??= now();

        if ($this->status->isClosedState()) {
            $finishedAt = $this->resolved_at ?? $this->closed_at ?? $now;

            return $finishedAt->lessThanOrEqualTo($this->due_at) ? SlaState::Met : SlaState::Breached;
        }

        if ($now->greaterThan($this->due_at)) {
            return SlaState::Breached;
        }

        if ($this->sla_warning_at !== null && $now->greaterThanOrEqualTo($this->sla_warning_at)) {
            return SlaState::Approaching;
        }

        return SlaState::Within;
    }

    protected function slaState(): Attribute
    {
        return Attribute::get(fn () => $this->computeSlaState());
    }

    public function isUnassigned(): bool
    {
        return $this->assigned_agent_id === null;
    }
}
