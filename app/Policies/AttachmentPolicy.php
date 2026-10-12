<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Files are private. A download is allowed only when the user may see the ticket AND,
 * for files attached to an internal note, is a staff member.
 */
class AttachmentPolicy
{
    public function download(User $user, Attachment $attachment): bool
    {
        $ticket = $attachment->ticket; // null when the ticket was soft deleted

        if ($ticket === null || ! Gate::forUser($user)->allows('view', $ticket)) {
            return false;
        }

        if ($attachment->reply_id !== null && $attachment->reply?->is_internal) {
            return $user->isStaff();
        }

        return true;
    }
}
