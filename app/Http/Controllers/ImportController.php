<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportTicketsRequest;
use App\Jobs\ProcessTicketImport;
use App\Models\ImportBatch;
use App\Services\Import\TicketCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** All routes are behind the `import-tickets` gate (see routes/web.php). */
class ImportController extends Controller
{
    public function index(): View
    {
        return view('imports.index', [
            'batches' => ImportBatch::query()->with('user:id,name')->latest('id')->paginate(15),
            'columns' => TicketCsvImporter::columns(),
            'required' => TicketCsvImporter::REQUIRED_COLUMNS,
        ]);
    }

    public function store(ImportTicketsRequest $request): RedirectResponse
    {
        $disk = config('tickets.import.disk');
        $file = $request->file('file');

        $batch = ImportBatch::query()->create([
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $file->store('imports', ['disk' => $disk]),
            'status' => ImportBatch::PENDING,
        ]);

        // Heavy work happens on the "imports" queue; the request returns immediately.
        ProcessTicketImport::dispatch($batch->id);

        return redirect()->route('imports.show', $batch)
            ->with('status', 'File uploaded. The import is running in the background.');
    }

    public function show(ImportBatch $batch): View
    {
        return view('imports.show', [
            'batch' => $batch,
            'problems' => $batch->errors()->orderBy('row_number')->limit(100)->get(),
        ]);
    }

    /** Polled by the progress bar. */
    public function progress(ImportBatch $batch): JsonResponse
    {
        return response()->json([
            'status' => $batch->status,
            'percent' => $batch->percent(),
            'total' => $batch->total_rows,
            'processed' => $batch->processed_rows,
            'imported' => $batch->imported_rows,
            'skipped' => $batch->skipped_rows,
            'failed' => $batch->failed_rows,
            'finished' => $batch->isFinished(),
            'message' => $batch->failure_message,
        ]);
    }

    /** Error report: every invalid / duplicate row with its original data. Streamed, never loaded fully. */
    public function errors(ImportBatch $batch): StreamedResponse
    {
        return response()->streamDownload(function () use ($batch): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row_number', 'message', 'original_data'], ',', '"', '');

            $batch->errors()->orderBy('row_number')->lazyById(1000)->each(function ($error) use ($out): void {
                fputcsv($out, [$error->row_number, $error->message, json_encode($error->raw, JSON_UNESCAPED_UNICODE)], ',', '"', '');
            });

            fclose($out);
        }, "import-{$batch->id}-errors.csv", ['Content-Type' => 'text/csv']);
    }

    public function template(Request $request): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, TicketCsvImporter::columns(), ',', '"', '');
            fputcsv($out, ['EXT-1001', 'Cannot log in', 'Password reset e-mail never arrives.', 'high', 'Account', 'jane@example.com', 'open', '2026-10-05 10:30:00', ''], ',', '"', '');
            fclose($out);
        }, 'tickets-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
