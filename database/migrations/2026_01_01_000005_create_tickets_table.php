<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 32)->unique();           // TKT-2026-000001
            $table->string('external_id', 100)->nullable()->unique(); // CSV import de-duplication key

            $table->string('subject');
            $table->text('description');
            $table->string('priority', 16);
            $table->string('status', 32);

            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();

            // SLA: snapshot at creation, so later policy edits never rewrite history.
            $table->unsignedInteger('sla_resolution_minutes');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('sla_warning_at')->nullable();

            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Idempotency markers for the SLA scheduler (one warning / escalation per ticket).
            $table->timestamp('warning_sent_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // --- Indexes: each one maps to a real query in the app -------------------
            $table->index(['status', 'due_at']);             // SLA breached scan, active lists, dashboard
            $table->index(['status', 'sla_warning_at']);     // "approaching" scan
            $table->index(['assigned_agent_id', 'status']);  // agent workload + "my tickets"
            $table->index(['created_by', 'created_at']);     // customer's own tickets
            $table->index(['priority', 'status']);           // filters + dashboard group by
            $table->index(['category_id', 'status']);        // category filter + auto-assign
            $table->index('created_at');                     // date range filter
        });

        // FULLTEXT is MySQL specific; SQLite (used by the test-suite) falls back to LIKE.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('tickets', function (Blueprint $table) {
                $table->fullText(['subject', 'description']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
