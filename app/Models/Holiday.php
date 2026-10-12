<?php

namespace App\Models;

use App\Services\Sla\BusinessSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Holiday extends Model
{
    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(BusinessSchedule::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }
}
