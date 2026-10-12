<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Services\Sla\SlaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SlaPolicy extends Model
{
    protected $fillable = ['priority', 'resolution_minutes', 'warning_threshold_percent'];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'resolution_minutes' => 'integer',
            'warning_threshold_percent' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The policy table is cached; any change must invalidate it.
        $flush = fn () => Cache::forget(SlaService::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }
}
