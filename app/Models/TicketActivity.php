<?php

namespace App\Models;

use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit record: updates are blocked at model level (and nothing in the
 * application deletes rows; they only disappear with their ticket via FK cascade).
 */
class TicketActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id', 'type', 'description', 'old_values', 'new_values', 'created_at'];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
