<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportError extends Model
{
    public $timestamps = false;

    protected $fillable = ['import_batch_id', 'row_number', 'message', 'raw'];

    protected function casts(): array
    {
        return ['raw' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
