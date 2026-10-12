<?php

namespace App\Models;

use App\Services\Sla\BusinessSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BusinessHour extends Model
{
    protected $fillable = ['day_of_week', 'is_working', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(BusinessSchedule::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }
}
