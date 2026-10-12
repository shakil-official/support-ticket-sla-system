<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'ticket_id', 'reply_id', 'uploaded_by', 'original_name', 'stored_name',
        'disk', 'path', 'mime', 'size', 'checksum',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'reply_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        $kb = $this->size / 1024;

        return $kb < 1024 ? number_format($kb, 1).' KB' : number_format($kb / 1024, 2).' MB';
    }
}
