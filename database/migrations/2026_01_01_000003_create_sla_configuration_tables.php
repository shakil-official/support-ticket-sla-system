<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per priority. Changing an SLA rule = editing a row, no code change.
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 16)->unique();
            $table->unsignedInteger('resolution_minutes');          // BUSINESS minutes
            $table->unsignedTinyInteger('warning_threshold_percent')->default(75);
            $table->timestamps();
        });

        // 0 = Sunday ... 6 = Saturday (Carbon::dayOfWeek)
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week')->unique();
            $table->boolean('is_working')->default(true);
            $table->time('start_time')->default('09:00:00');
            $table->time('end_time')->default('18:00:00');
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('business_hours');
        Schema::dropIfExists('sla_policies');
    }
};
