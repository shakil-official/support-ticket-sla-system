<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'original_name', 'disk', 'path', 'status', 'total_rows', 'processed_rows',
        'imported_rows', 'skipped_rows', 'failed_rows', 'failure_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED], true);
    }

    public function percent(): int
    {
        if ($this->status === self::COMPLETED) {
            return 100;
        }

        return $this->total_rows > 0
            ? (int) min(100, floor($this->processed_rows / $this->total_rows * 100))
            : 0;
    }
}
