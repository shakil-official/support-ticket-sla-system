<?php

namespace App\Jobs;

use App\Services\Sla\SlaEscalationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Escalates ONE breached ticket (flag + history + notifications). Idempotent, see WarnTicketSla. */
class EscalateTicketSla implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $ticketId)
    {
        $this->onQueue('sla');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(SlaEscalationService $escalation): void
    {
        $escalation->escalate($this->ticketId);
    }
}
