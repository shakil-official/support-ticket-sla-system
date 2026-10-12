<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\TicketCsvImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Long running CSV import on its own queue ("imports") so e-mails and SLA jobs are never
 * stuck behind it. $tries = 1: the chunks that already committed must not be re-run
 * blindly. IMPORTANT: the queue connection's retry_after must be larger than $timeout
 * (REDIS_QUEUE_RETRY_AFTER in .env), otherwise the broker would hand the running job to a
 * second worker.
 */
class ProcessTicketImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $batchId)
    {
        $this->onQueue('imports');
    }

    public function handle(TicketCsvImporter $importer): void
    {
        $importer->run(ImportBatch::query()->findOrFail($this->batchId));
    }

    public function failed(Throwable $e): void
    {
        ImportBatch::query()->whereKey($this->batchId)->update([
            'status' => ImportBatch::FAILED,
            'failure_message' => Str::limit($e->getMessage(), 480, '...'),
            'finished_at' => now(),
        ]);
    }
}
