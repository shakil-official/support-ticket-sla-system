<?php

namespace App\Console\Commands;

use App\Jobs\EscalateTicketSla;
use App\Jobs\WarnTicketSla;
use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * Scheduler entry point (every 5 minutes, see routes/console.php).
 *
 * The command itself does almost nothing: it finds CANDIDATES with two cheap indexed queries
 * (`status, due_at` / `status, sla_warning_at`), reads them in chunks of ids (constant memory,
 * no OFFSET) and dispatches one small job per ticket. The per-ticket work therefore runs in
 * parallel on the queue workers and a failure of one ticket never blocks the others.
 */
class CheckSlaCommand extends Command
{
    protected $signature = 'tickets:check-sla';

    protected $description = 'Warn about tickets approaching their SLA and escalate tickets that breached it';

    public function handle(): int
    {
        $now = now();
        $chunk = (int) config('tickets.sla.scan_chunk', 200);
        $warned = 0;
        $escalated = 0;

        // 1) Approaching: past the warning point, not yet due, not warned before.
        Ticket::query()
            ->active()
            ->whereNull('warning_sent_at')
            ->where('sla_warning_at', '<=', $now)
            ->where('due_at', '>', $now)
            ->select('tickets.id')
            ->chunkById($chunk, function ($tickets) use (&$warned): void {
                foreach ($tickets as $ticket) {
                    WarnTicketSla::dispatch($ticket->id);
                    $warned++;
                }
            }, 'tickets.id', 'id');

        // 2) Breached: past the deadline and never escalated.
        Ticket::query()
            ->active()
            ->whereNull('escalated_at')
            ->where('due_at', '<', $now)
            ->select('tickets.id')
            ->chunkById($chunk, function ($tickets) use (&$escalated): void {
                foreach ($tickets as $ticket) {
                    EscalateTicketSla::dispatch($ticket->id);
                    $escalated++;
                }
            }, 'tickets.id', 'id');

        $this->info("SLA check done: {$warned} warning job(s), {$escalated} escalation job(s) dispatched.");

        return self::SUCCESS;
    }
}
