<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONLY way to get a file. Files live on a private disk, so there is no public URL;
 * every download passes through AttachmentPolicy (ticket visibility + internal notes).
 */
class AttachmentController extends Controller
{
    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorize('download', $attachment);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',   // never let the browser "guess" an executable type
        ]);
    }
}
