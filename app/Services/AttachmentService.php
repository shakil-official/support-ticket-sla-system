<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Validation (type/size/count) happens in the FormRequests. This class is the storage half:
 *  - files go to a PRIVATE disk (storage/app/private), never under public/
 *  - the stored name is a random UUID; the user supplied name is only metadata
 *  - the extension comes from the file CONTENT (guessExtension), not from the client
 *  - downloads always go through AttachmentController + AttachmentPolicy
 */
class AttachmentService
{
    public function store(UploadedFile $file, Ticket $ticket, ?TicketReply $reply, User $uploader): Attachment
    {
        $disk = config('tickets.attachments.disk');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        $path = $file->storeAs("tickets/{$ticket->id}", $storedName, ['disk' => $disk]);

        if ($path === false) {
            throw new RuntimeException('The attachment could not be stored.');
        }

        return Attachment::create([
            'ticket_id' => $ticket->id,
            'reply_id' => $reply?->id,
            'uploaded_by' => $uploader->id,
            'original_name' => $this->cleanName($file->getClientOriginalName()),
            'stored_name' => $storedName,
            'disk' => $disk,
            'path' => $path,
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    /**
     * Remove files of attachments whose DB transaction was rolled back.
     *
     * @param  iterable<Attachment>  $attachments
     */
    public function deleteFiles(iterable $attachments): void
    {
        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit($name !== '' ? $name : 'file', 200, '');
    }
}
