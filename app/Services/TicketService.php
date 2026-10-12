<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\ReplyAdded;
use App\Events\TicketCreated;
use App\Events\TicketResolved;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Sla\SlaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Application service for the ticket lifecycle. Controllers stay thin and call this class;
 * every public method is ONE database transaction (change + audit entry commit together)
 * and events are dispatched only AFTER the commit, so listeners never see rolled back data.
 */
class TicketService
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaService $sla,
        private readonly ActivityLogger $activity,
        private readonly AttachmentService $attachments,
        private readonly TicketStatusManager $status,
    ) {}

    /**
     * @param  array{subject: string, description: string, priority: string, category_id: int|string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(User $creator, array $data, array $files = []): Ticket
    {
        $stored = [];

        try {
            $ticket = DB::transaction(function () use ($creator, $data, $files, &$stored): Ticket {
                $now = now();
                $priority = TicketPriority::from($data['priority']);

                $ticket = new Ticket(Arr::only($data, ['subject', 'description', 'priority', 'category_id']));
                $ticket->forceFill([
                    'ticket_number' => $this->numbers->next($now),   // takes the sequence row lock
                    'created_by' => $creator->id,
                    'status' => TicketStatus::Open,
                    'created_at' => $now,
                    'updated_at' => $now,
                ] + $this->sla->calculate($priority, $now));
                $ticket->save();

                $this->activity->log(
                    $ticket, ActivityType::Created, "Ticket created by {$creator->name}", $creator,
                    null, ['priority' => $priority->value, 'category_id' => (int) $data['category_id']],
                );

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($file, $ticket, null, $creator);
                }

                if ($files !== []) {
                    $this->activity->log($ticket, ActivityType::Attachment, count($files).' file(s) attached', $creator);
                }

                return $ticket;
            });
        } catch (Throwable $e) {
            $this->attachments->deleteFiles($stored);   // the DB rolled back; don't orphan files
            throw $e;
        }

        TicketCreated::dispatch($ticket);

        return $ticket;
    }

    /**
     * Update editable fields. Changing the priority recalculates the SLA (from the ticket's
     * created_at, with the new priority's rule) and each change gets its own audit line.
     *
     * @param  array<string, mixed>  $data  subset of subject, description, priority, category_id
     */
    public function update(Ticket $ticket, User $actor, array $data): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $data): Ticket {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            $oldPriority = $ticket->priority;
            $oldCategoryId = $ticket->category_id;

            $ticket->fill(Arr::only($data, ['subject', 'description', 'priority', 'category_id']));

            $changedFields = array_keys($ticket->getDirty());

            if ($ticket->isDirty('priority')) {
                $this->sla->applyTo($ticket);
                // New deadline -> let the scheduler judge the ticket against it from scratch.
                $ticket->warning_sent_at = null;
                $ticket->escalated_at = null;
            }

            $ticket->save();

            if (in_array('priority', $changedFields, true)) {
                $this->activity->log(
                    $ticket, ActivityType::PriorityChanged,
                    "Priority changed from {$oldPriority->label()} to {$ticket->priority->label()} (SLA recalculated)",
                    $actor,
                    ['priority' => $oldPriority->value],
                    ['priority' => $ticket->priority->value, 'due_at' => $ticket->due_at?->toIso8601String()],
                );
            }

            if (in_array('category_id', $changedFields, true)) {
                $old = Category::query()->find($oldCategoryId)?->name ?? '#'.$oldCategoryId;
                $new = Category::query()->find($ticket->category_id)?->name ?? '#'.$ticket->category_id;

                $this->activity->log(
                    $ticket, ActivityType::CategoryChanged, "Category changed from {$old} to {$new}", $actor,
                    ['category_id' => $oldCategoryId], ['category_id' => $ticket->category_id],
                );
            }

            $textFields = array_values(array_intersect($changedFields, ['subject', 'description']));
            if ($textFields !== []) {
                $this->activity->log(
                    $ticket, ActivityType::Updated, 'Ticket details updated ('.implode(', ', $textFields).')', $actor,
                );
            }

            return $ticket;
        });
    }

    /** @throws \App\Exceptions\InvalidTicketTransitionException */
    public function changeStatus(Ticket $ticket, TicketStatus $to, ?User $actor = null, ?string $reason = null): Ticket
    {
        $changed = false;

        $locked = DB::transaction(function () use ($ticket, $to, $actor, $reason, &$changed): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $changed = $this->status->transition($locked, $to, $actor, $reason);

            return $locked;
        });

        if ($changed && $to === TicketStatus::Resolved) {
            TicketResolved::dispatch($locked, $actor);
        }

        return $locked;
    }

    /**
     * Add a public reply or an internal note.
     *
     * Status rules (kept here, in one place):
     *  - staff public reply on Open/In Progress  -> "Waiting for Customer" (+ first_response_at)
     *  - customer reply on Waiting               -> back to In Progress (Open if unassigned)
     *  - customer reply on Resolved              -> ticket is reopened
     *  - internal notes never change the status
     *
     * @param  list<UploadedFile>  $files
     */
    public function addReply(Ticket $ticket, User $author, string $body, bool $internal = false, array $files = []): TicketReply
    {
        $stored = [];

        try {
            $reply = DB::transaction(function () use ($ticket, $author, $body, $internal, $files, &$stored): TicketReply {
                $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

                $reply = $locked->replies()->create([
                    'user_id' => $author->id,
                    'body' => $body,
                    'is_internal' => $internal,
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($file, $locked, $reply, $author);
                }

                if ($files !== []) {
                    $this->activity->log($locked, ActivityType::Attachment, count($files).' file(s) attached to a '.($internal ? 'note' : 'reply'), $author);
                }

                if ($internal) {
                    $this->activity->log($locked, ActivityType::InternalNote, "Internal note added by {$author->name}", $author);
                } else {
                    $role = $author->isStaff() ? 'Agent' : 'Customer';
                    $this->activity->log($locked, ActivityType::Replied, "{$role} {$author->name} replied", $author);
                    $this->applyReplyStatusRules($locked, $author);
                }

                return $reply;
            });
        } catch (Throwable $e) {
            $this->attachments->deleteFiles($stored);
            throw $e;
        }

        if (! $internal) {
            ReplyAdded::dispatch($reply);
        }

        return $reply;
    }

    private function applyReplyStatusRules(Ticket $ticket, User $author): void
    {
        if ($author->isStaff()) {
            if ($ticket->first_response_at === null) {
                $ticket->first_response_at = now();
                $ticket->save();
            }

            if (in_array($ticket->status, [TicketStatus::Open, TicketStatus::InProgress], true)) {
                $this->status->transition($ticket, TicketStatus::WaitingForCustomer, $author, 'agent replied');
            }

            return;
        }

        // Customer replied.
        if ($ticket->status === TicketStatus::WaitingForCustomer) {
            $this->status->transition($ticket, TicketStatus::InProgress, $author, 'customer replied');
        } elseif ($ticket->status === TicketStatus::Resolved) {
            $reopenAs = $ticket->isUnassigned() ? TicketStatus::Open : TicketStatus::InProgress;
            $this->status->transition($ticket, $reopenAs, $author, 'customer replied, ticket reopened');
        }
    }

    public function delete(Ticket $ticket): void
    {
        $ticket->delete(); // soft delete: history and files are kept
    }
}
