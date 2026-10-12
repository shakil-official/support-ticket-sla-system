<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Base class of every ticket notification.
 *
 *  - ShouldQueue + dedicated "notifications" queue: sending e-mail never blocks the HTTP
 *    request, and a big CSV import on the "imports" queue can never delay e-mails.
 *  - channels: mail + database (the database rows power the bell icon in the UI).
 *  - SerializesModels: the queue stores only the ticket id; the worker re-reads fresh data.
 *  - retries with back-off; a ticket deleted in the meantime just drops the notification.
 */
abstract class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Ticket $ticket)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> seconds between retries */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** Short e-mail subject / bell title. */
    abstract protected function title(): string;

    /** One sentence explaining what happened. */
    abstract protected function message(): string;

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->ticket->due_at?->copy()->timezone(config('tickets.timezone'))->format('M j, Y g:i A');

        $mail = (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] {$this->title()}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message())
            ->line("Ticket: {$this->ticket->ticket_number} - {$this->ticket->subject}")
            ->line('Priority: '.$this->ticket->priority->label());

        if ($due !== null && $notifiable->isStaff()) {
            $mail->line("SLA due: {$due} (".config('tickets.timezone').')');
        }

        return $mail->action('Open ticket', route('tickets.show', $this->ticket));
    }

    /** Stored in `notifications.data` and rendered by the bell / notifications page. */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_number' => $this->ticket->ticket_number,
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => route('tickets.show', $this->ticket, absolute: false),
        ];
    }
}
