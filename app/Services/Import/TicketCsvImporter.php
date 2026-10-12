<?php

namespace App\Services\Import;

use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportError;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Sla\SlaService;
use App\Services\TicketNumberGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Streams a (possibly huge) CSV file into tickets.
 *
 *  MEMORY      the file is read record by record with fgetcsv() from a stream; at most one
 *              chunk (config tickets.import.chunk_size, default 500 rows) is in memory.
 *  SPEED       one chunk = one transaction = a handful of queries, NOT one query per row:
 *              set based look-ups (existing external ids, customers), one reserved block of
 *              ticket numbers, one multi-row INSERT for tickets and one for the audit rows.
 *  VALIDATION  every row is validated; bad rows are stored in `import_errors` (row number,
 *              message, raw data) and never abort the import.
 *  DUPLICATES  a row whose `external_id` already exists (in the database or earlier in the
 *              same file) is skipped and reported, so re-uploading a file is idempotent.
 *              Rows without external_id cannot be de-duplicated.
 *  PROGRESS    counters on `import_batches` are updated in the same transaction as the
 *              data of the chunk, so the progress bar is always truthful.
 *  SIDE EFFECTS  deliberately none: no notifications, no auto-assignment. Importing 50k
 *              historical tickets must not send 50k e-mails. Imported tickets that are
 *              already past their warning/deadline are flagged as warned/escalated so the
 *              SLA scheduler does not mass-escalate them either.
 *
 * Columns: subject, description, priority, category (name or slug), customer_email are required;
 * external_id, status, created_at, assigned_agent_email are optional. Unknown customers are
 * created as customer accounts with an unusable random password (they use "forgot password").
 */
class TicketCsvImporter
{
    public const REQUIRED_COLUMNS = ['subject', 'description', 'priority', 'category', 'customer_email'];

    public const OPTIONAL_COLUMNS = ['external_id', 'status', 'created_at', 'assigned_agent_email'];

    /** @var array<string, int>|null  lower-cased name/slug => category id */
    private ?array $categories = null;

    /** @var array<string, int>|null  lower-cased e-mail => agent id */
    private ?array $agents = null;

    private ?string $placeholderPassword = null;

    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaService $sla,
    ) {}

    /** @return list<string> */
    public static function columns(): array
    {
        return [...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS];
    }

    public function run(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportBatch::PROCESSING, 'started_at' => now(), 'failure_message' => null]);

        // Pass 1: header check + row count (needed for a meaningful progress bar). Constant memory.
        $stream = $this->open($batch);
        try {
            $header = $this->readHeader($stream);
            $missing = $header === null ? self::REQUIRED_COLUMNS : array_values(array_diff(self::REQUIRED_COLUMNS, $header));

            if ($missing !== []) {
                $this->finish($batch, ImportBatch::FAILED, 'Missing required column(s): '.implode(', ', $missing));

                return;
            }

            $total = $this->countRecords($stream);
        } finally {
            fclose($stream);
        }

        $batch->update(['total_rows' => $total]);

        // Pass 2: process in chunks.
        $size = max(1, (int) config('tickets.import.chunk_size', 500));
        $stream = $this->open($batch);
        try {
            $this->readHeader($stream);
            $chunk = [];
            $rowNumber = 1; // the header is row 1 -> the first data row is row 2, like in a spreadsheet

            while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rowNumber++;

                if ($this->isBlank($record)) {
                    continue;
                }

                $chunk[$rowNumber] = $this->combine($header, $record);

                if (count($chunk) >= $size) {
                    $this->processChunk($batch, $chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->processChunk($batch, $chunk);
            }
        } finally {
            fclose($stream);
        }

        $this->finish($batch, ImportBatch::COMPLETED);
        Storage::disk($batch->disk)->delete($batch->path); // the source file is no longer needed
    }

    /**
     * Imports one chunk atomically.
     *
     * @param  array<int, array<string, string|null>>  $rows  row number => raw associative row
     */
    public function processChunk(ImportBatch $batch, array $rows): void
    {
        DB::transaction(function () use ($batch, $rows): void {
            $errors = [];
            $skipped = 0;
            $candidates = [];

            // 1) validate + normalise every row ------------------------------------------
            foreach ($rows as $number => $raw) {
                $row = $this->normalise($raw);
                $validator = Validator::make($row, $this->rules());

                if ($validator->fails()) {
                    $errors[] = $this->error($batch, $number, implode('; ', $validator->errors()->all()), $raw);

                    continue;
                }

                $candidates[$number] = [$row, $raw];
            }

            // 2) duplicates (database + inside the file) ----------------------------------
            $existing = $this->existingExternalIds($candidates);
            $seen = [];

            foreach ($candidates as $number => [$row, $raw]) {
                $key = $row['external_id'] !== null ? mb_strtolower($row['external_id']) : null;

                if ($key !== null && (isset($existing[$key]) || isset($seen[$key]))) {
                    unset($candidates[$number]);
                    $skipped++;
                    $errors[] = $this->error($batch, $number, "Duplicate external_id \"{$row['external_id']}\" - row skipped", $raw);

                    continue;
                }

                if ($key !== null) {
                    $seen[$key] = true;
                }
            }

            // 3) resolve references (category, agent, customer) ----------------------------
            $customerIds = $this->resolveCustomers(array_map(fn ($c) => $c[0]['customer_email'], $candidates));
            $ready = [];

            foreach ($candidates as $number => [$row, $raw]) {
                $categoryId = $this->categoryId($row['category']);
                if ($categoryId === null) {
                    $errors[] = $this->error($batch, $number, "Unknown category \"{$row['category']}\"", $raw);

                    continue;
                }

                $agentId = null;
                if ($row['assigned_agent_email'] !== null) {
                    $agentId = $this->agentId($row['assigned_agent_email']);
                    if ($agentId === null) {
                        $errors[] = $this->error($batch, $number, "No support agent with e-mail \"{$row['assigned_agent_email']}\"", $raw);

                        continue;
                    }
                }

                try {
                    $createdAt = $row['created_at'] !== null
                        ? Carbon::parse($row['created_at'], config('tickets.timezone'))->setTimezone(config('app.timezone'))
                        : now();
                } catch (Throwable) {
                    $errors[] = $this->error($batch, $number, 'created_at is not a valid date', $raw);

                    continue;
                }

                if ($createdAt->isFuture()) {
                    $errors[] = $this->error($batch, $number, 'created_at is in the future', $raw);

                    continue;
                }

                $ready[$number] = [$row, $categoryId, $agentId, $createdAt];
            }

            // 4) insert tickets + audit rows with multi-row INSERTs -------------------------
            if ($ready !== []) {
                $this->insertTickets($batch, $ready, $customerIds);
            }

            if ($errors !== []) {
                foreach (array_chunk($errors, 500) as $part) {
                    ImportError::query()->insert($part);
                }
            }

            // 5) progress counters (same transaction => always consistent) ------------------
            $processed = count($rows);
            $imported = count($ready);
            $failed = $processed - $imported - $skipped;

            ImportBatch::query()->whereKey($batch->id)->update([
                'processed_rows' => DB::raw('processed_rows + '.(int) $processed),
                'imported_rows' => DB::raw('imported_rows + '.(int) $imported),
                'skipped_rows' => DB::raw('skipped_rows + '.(int) $skipped),
                'failed_rows' => DB::raw('failed_rows + '.(int) $failed),
            ]);
        });
    }

    // ---------------------------------------------------------------------------------

    /** @param  array<int, array{0: array<string, mixed>, 1: int, 2: int|null, 3: Carbon}>  $ready */
    private function insertTickets(ImportBatch $batch, array $ready, array $customerIds): void
    {
        $now = now();
        $format = 'Y-m-d H:i:s';
        $numbers = $this->numbers->reserve(count($ready), $now);
        $tickets = [];
        $meta = []; // ticket_number => csv row number

        foreach (array_keys($ready) as $i => $rowNumber) {
            [$row, $categoryId, $agentId, $createdAt] = $ready[$rowNumber];

            $priority = TicketPriority::from($row['priority']);
            $status = TicketStatus::from($row['status'] ?? TicketStatus::Open->value);
            $sla = $this->sla->calculate($priority, $createdAt);
            $number = $numbers[$i];

            $isActive = $status->isActive();
            $breached = $isActive && $sla['due_at']->lessThan($now);
            $warned = $isActive && ($breached || $sla['sla_warning_at']->lessThanOrEqualTo($now));

            $tickets[] = [
                'ticket_number' => $number,
                'external_id' => $row['external_id'],
                'subject' => $row['subject'],
                'description' => $row['description'],
                'priority' => $priority->value,
                'status' => $status->value,
                'category_id' => $categoryId,
                'created_by' => $customerIds[$row['customer_email']],
                'assigned_agent_id' => $agentId,
                'sla_resolution_minutes' => $sla['sla_resolution_minutes'],
                'due_at' => $sla['due_at']->format($format),
                'sla_warning_at' => $sla['sla_warning_at']->format($format),
                'first_response_at' => null,
                'resolved_at' => $isActive ? null : $now->format($format),
                'closed_at' => $status === TicketStatus::Closed ? $now->format($format) : null,
                'warning_sent_at' => $warned ? $now->format($format) : null,
                'escalated_at' => $breached ? $now->format($format) : null,
                'escalation_level' => 0,
                'created_at' => $createdAt->format($format),
                'updated_at' => $now->format($format),
                'deleted_at' => null,
            ];

            $meta[$number] = $rowNumber;
        }

        Ticket::query()->insert($tickets);

        $ids = Ticket::query()->whereIn('ticket_number', array_keys($meta))->pluck('id', 'ticket_number');
        $activities = [];

        foreach ($meta as $number => $rowNumber) {
            $activities[] = [
                'ticket_id' => $ids[$number],
                'user_id' => $batch->user_id,
                'type' => ActivityType::Imported->value,
                'description' => "Imported from CSV \"{$batch->original_name}\" (row {$rowNumber})",
                'old_values' => null,
                'new_values' => json_encode(['import_batch_id' => $batch->id, 'row' => $rowNumber]),
                'created_at' => $now->format($format),
            ];
        }

        TicketActivity::query()->insert($activities);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'external_id' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'in:'.implode(',', TicketPriority::values())],
            'category' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email', 'max:255'],
            'status' => ['nullable', 'in:'.implode(',', array_map(fn (TicketStatus $s) => $s->value, TicketStatus::cases()))],
            'created_at' => ['nullable', 'date'],
            'assigned_agent_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * Trim, scrub invalid UTF-8, turn "" into null and canonicalise enum-like columns
     * ("High" -> "high", "In Progress" -> "in_progress", e-mails -> lower case).
     *
     * @param  array<string, string|null>  $raw
     * @return array<string, string|null>
     */
    private function normalise(array $raw): array
    {
        $row = [];

        foreach (self::columns() as $column) {
            $value = $raw[$column] ?? null;
            $value = $value === null ? null : trim(mb_scrub($value));
            $row[$column] = $value === '' ? null : $value;
        }

        foreach (['priority', 'status'] as $column) {
            if ($row[$column] !== null) {
                $row[$column] = str_replace([' ', '-'], '_', mb_strtolower($row[$column]));
            }
        }

        foreach (['customer_email', 'assigned_agent_email'] as $column) {
            if ($row[$column] !== null) {
                $row[$column] = mb_strtolower($row[$column]);
            }
        }

        return $row;
    }

    /**
     * @param  array<int, array{0: array<string, mixed>, 1: array}>  $candidates
     * @return array<string, true>  lower-cased external ids that already exist
     */
    private function existingExternalIds(array $candidates): array
    {
        $ids = [];
        foreach ($candidates as [$row]) {
            if ($row['external_id'] !== null) {
                $ids[] = $row['external_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        $found = [];
        foreach (Ticket::withTrashed()->whereIn('external_id', $ids)->pluck('external_id') as $externalId) {
            $found[mb_strtolower($externalId)] = true;
        }

        return $found;
    }

    /**
     * One SELECT for all e-mails of the chunk, then create the missing customers.
     *
     * @param  list<string>  $emails
     * @return array<string, int>  e-mail => user id
     */
    private function resolveCustomers(array $emails): array
    {
        $emails = array_values(array_unique($emails));

        if ($emails === []) {
            return [];
        }

        $map = [];
        foreach (User::query()->whereIn('email', $emails)->get(['id', 'email']) as $user) {
            $map[mb_strtolower($user->email)] = $user->id;
        }

        foreach ($emails as $email) {
            if (isset($map[$email])) {
                continue;
            }

            $user = new User;
            $user->forceFill([
                'name' => Str::headline(Str::before($email, '@')),
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'role' => UserRole::Customer,
            ])->save();

            $map[$email] = $user->id;
        }

        return $map;
    }

    /** One random, never-disclosed hash shared by all accounts created by this import. */
    private function placeholderPassword(): string
    {
        return $this->placeholderPassword ??= Hash::make(Str::random(48));
    }

    private function categoryId(string $name): ?int
    {
        if ($this->categories === null) {
            $this->categories = [];
            foreach (Category::query()->get(['id', 'name', 'slug']) as $category) {
                $this->categories[mb_strtolower($category->name)] = $category->id;
                $this->categories[mb_strtolower($category->slug)] = $category->id;
            }
        }

        return $this->categories[mb_strtolower($name)] ?? null;
    }

    private function agentId(string $email): ?int
    {
        if ($this->agents === null) {
            $this->agents = [];
            foreach (User::query()->agents()->get(['id', 'email']) as $agent) {
                $this->agents[mb_strtolower($agent->email)] = $agent->id;
            }
        }

        return $this->agents[$email] ?? null;
    }

    /** @return array<string, mixed> */
    private function error(ImportBatch $batch, int $rowNumber, string $message, array $raw): array
    {
        return [
            'import_batch_id' => $batch->id,
            'row_number' => $rowNumber,
            'message' => Str::limit($message, 480, '...'),
            'raw' => json_encode(
                array_map(fn ($v) => is_string($v) ? Str::limit(mb_scrub($v), 300, '...') : $v, $raw),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
        ];
    }

    private function finish(ImportBatch $batch, string $status, ?string $message = null): void
    {
        $batch->update(['status' => $status, 'failure_message' => $message, 'finished_at' => now()]);
    }

    /** @return resource */
    private function open(ImportBatch $batch)
    {
        $stream = Storage::disk($batch->disk)->readStream($batch->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Import file [{$batch->path}] could not be opened.");
        }

        return $stream;
    }

    /**
     * @param  resource  $stream
     * @return list<string>|null  normalised column names
     */
    private function readHeader($stream): ?array
    {
        $record = fgetcsv($stream, null, ',', '"', '');

        if ($record === false || $this->isBlank($record)) {
            return null;
        }

        $record[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $record[0]); // UTF-8 BOM from Excel

        return array_map(
            fn ($name) => str_replace([' ', '-'], '_', mb_strtolower(trim((string) $name))),
            $record,
        );
    }

    /** @param  resource  $stream */
    private function countRecords($stream): int
    {
        $count = 0;

        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if (! $this->isBlank($record)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param  array<int, string|null>  $record */
    private function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if ($value !== null && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $header
     * @param  array<int, string|null>  $record
     * @return array<string, string|null>
     */
    private function combine(array $header, array $record): array
    {
        $width = count($header);
        $record = array_slice(array_pad($record, $width, null), 0, $width);

        return array_combine($header, $record);
    }
}
