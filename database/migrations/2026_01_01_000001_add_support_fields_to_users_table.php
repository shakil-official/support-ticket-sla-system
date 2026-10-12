<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin | agent | customer  (kept as string + enum cast: no DB enum migrations later)
            $table->string('role', 20)->default('customer')->after('password');
            $table->boolean('is_available')->default(true)->after('role');
            // used as the tie-breaker of the auto-assignment strategy
            $table->timestamp('last_assigned_at')->nullable()->after('is_available');

            $table->index(['role', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'is_available']);
            $table->dropColumn(['role', 'is_available', 'last_assigned_at']);
        });
    }
};
