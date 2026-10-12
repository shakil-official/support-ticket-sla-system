<?php

namespace Tests\Feature;

use App\Jobs\ProcessTicketImport;
use App\Models\ImportBatch;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Import\TicketCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase, SeedsReferenceData;

    private const HEADER = "external_id,subject,description,priority,category,customer_email,status,created_at,assigned_agent_email\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Storage::fake('local');
        Notification::fake();
    }

    private function runImport(string $csv, int $chunk = 500): ImportBatch
    {
        config(['tickets.import.chunk_size' => $chunk]);
        Storage::disk('local')->put('imports/test.csv', $csv);

        $batch = ImportBatch::create([
            'user_id' => User::factory()->admin()->create()->id, 'original_name' => 'test.csv',
            'disk' => 'local', 'path' => 'imports/test.csv', 'status' => ImportBatch::PENDING,
        ]);

        app(TicketCsvImporter::class)->run($batch);

        return $batch->fresh();
    }

    public function test_valid_rows_are_imported_with_sla_and_without_notifications(): void
    {
        $recent = now()->subDays(3)->format('Y-m-d H:i:s');
        $older = now()->subDays(10)->format('Y-m-d H:i:s');

        $batch = $this->runImport(self::HEADER.
            "E-1,Cannot log in,Reset mail missing,high,General,jane@example.com,open,{$recent},\n".
            "E-2,Invoice wrong,Seats mismatch,medium,general,joe@example.com,resolved,{$older},\n");

        $this->assertSame(ImportBatch::COMPLETED, $batch->status);
        $this->assertSame(2, $batch->imported_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertSame(2, Ticket::query()->count());

        $ticket = Ticket::query()->where('external_id', 'E-1')->firstOrFail();
        $this->assertSame('jane@example.com', $ticket->creator->email);
        $this->assertTrue($ticket->creator->isCustomer());
        $this->assertNotNull($ticket->due_at);
        Notification::assertNothingSent();   // importing history must never mail anybody
    }

    public function test_invalid_rows_are_reported_and_do_not_stop_the_import(): void
    {
        $batch = $this->runImport(self::HEADER.
            "E-1,Good row,Fine,low,General,ok@example.com,,,\n".
            "E-2,,No subject,low,General,ok@example.com,,,\n".
            "E-3,Bad priority,Text,urgent,General,ok@example.com,,,\n".
            "E-4,Unknown category,Text,low,Nope,ok@example.com,,,\n".
            "E-5,Bad email,Text,low,General,not-an-email,,,\n");

        $this->assertSame(1, $batch->imported_rows);
        $this->assertSame(4, $batch->failed_rows);
        $this->assertSame(5, $batch->processed_rows);
        $this->assertSame(4, $batch->errors()->count());
        $this->assertEqualsCanonicalizing([3, 4, 5, 6], $batch->errors()->pluck('row_number')->all());
    }

    public function test_duplicates_are_skipped_inside_the_file_and_against_the_database(): void
    {
        $row = fn (string $id) => "{$id},Subject,Description,low,General,dup@example.com,,,\n";

        $first = $this->runImport(self::HEADER.$row('D-1').$row('D-1').$row('D-2'));
        $this->assertSame(2, $first->imported_rows);
        $this->assertSame(1, $first->skipped_rows);

        $again = $this->runImport(self::HEADER.$row('D-1').$row('D-2').$row('D-3'));   // re-upload
        $this->assertSame(1, $again->imported_rows);
        $this->assertSame(2, $again->skipped_rows);
        $this->assertSame(3, Ticket::query()->count());
    }

    public function test_a_file_without_required_columns_fails_cleanly(): void
    {
        $batch = $this->runImport("subject,priority\nHello,low\n");

        $this->assertSame(ImportBatch::FAILED, $batch->status);
        $this->assertStringContainsString('Missing required column', $batch->failure_message);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_many_rows_are_processed_in_chunks_and_get_unique_ticket_numbers(): void
    {
        $csv = self::HEADER;
        for ($i = 1; $i <= 25; $i++) {
            $csv .= "BULK-{$i},Subject {$i},Description {$i},low,General,bulk@example.com,,,\n";
        }

        $batch = $this->runImport($csv, chunk: 10); // 3 chunks: 10 + 10 + 5

        $this->assertSame(25, $batch->imported_rows);
        $this->assertSame(100, $batch->percent());
        $this->assertSame(25, Ticket::query()->distinct()->count('ticket_number'));
    }

    public function test_quoted_fields_with_commas_and_newlines_survive(): void
    {
        $this->runImport(self::HEADER."Q-1,\"Hello, world\",\"line one\nline two\",low,General,q@example.com,,,\n");

        $ticket = Ticket::query()->where('external_id', 'Q-1')->firstOrFail();
        $this->assertSame('Hello, world', $ticket->subject);
        $this->assertSame("line one\nline two", $ticket->description);
    }

    // ---- HTTP layer -------------------------------------------------------------------

    public function test_upload_is_queued_not_processed_inside_the_request(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('t.csv', self::HEADER),
        ]);

        $batch = ImportBatch::query()->firstOrFail();
        $response->assertRedirect(route('imports.show', $batch));
        Queue::assertPushedOn('imports', ProcessTicketImport::class);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_upload_rejects_non_csv_files_and_non_admins(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('imports.store'), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->actingAs(User::factory()->agent()->create())
            ->post(route('imports.store'), ['file' => UploadedFile::fake()->createWithContent('t.csv', self::HEADER)])
            ->assertForbidden();
    }

    public function test_progress_endpoint_reports_counters(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = ImportBatch::create([
            'user_id' => $admin->id, 'original_name' => 'p.csv', 'disk' => 'local', 'path' => 'x',
            'status' => ImportBatch::PROCESSING, 'total_rows' => 200, 'processed_rows' => 50,
        ]);

        $this->actingAs($admin)->getJson(route('imports.progress', $batch))
            ->assertOk()->assertJson(['percent' => 25, 'finished' => false, 'total' => 200]);
    }
}
