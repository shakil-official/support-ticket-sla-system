<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Throwable;

/** Closes tickets that have been "Resolved" for TICKET_AUTO_CLOSE_DAYS days without a customer reply. */
class AutoCloseResolvedCommand extends Command
{
    protected $signature = 'tickets:auto-close';

    protected $description = 'Close tickets that stayed resolved for the configured number of days';

    public function handle(TicketService $tickets): int
    {
        $days = (int) config('tickets.auto_close_days');

        if ($days <= 0) {
            $this->info('Auto-close is disabled (TICKET_AUTO_CLOSE_DAYS=0).');

            return self::SUCCESS;
        }

        $closed = 0;

        Ticket::query()
            ->where('status', TicketStatus::Resolved->value)
            ->where('resolved_at', '<=', now()->subDays($days))
            ->select('tickets.id')
            ->chunkById(200, function ($rows) use ($tickets, &$closed): void {
                foreach ($rows as $row) {
                    try {
                        $tickets->changeStatus(
                            Ticket::query()->findOrFail($row->id),
                            TicketStatus::Closed,
                            null,
                            'auto-closed after '.config('tickets.auto_close_days').' days',
                        );
                        $closed++;
                    } catch (Throwable $e) {
                        report($e); // one bad ticket must not stop the run
                    }
                }
            }, 'tickets.id', 'id');

        $this->info("Auto-closed {$closed} ticket(s).");

        return self::SUCCESS;
    }
}
