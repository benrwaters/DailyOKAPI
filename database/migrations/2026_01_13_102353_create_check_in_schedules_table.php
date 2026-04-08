<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('check_in_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->string('cadence')->default('daily'); // daily, twice_daily, custom
            $table->string('timezone')->default('Europe/London');

            // "HH:mm" local to patient timezone
            $table->string('check_in_time_local')->default('10:00');

            // how long after due time counts as "late" before escalation (optional later)
            $table->unsignedSmallInteger('grace_minutes')->default(120);

            // reminder push offset (e.g. 30 minutes before)
            $table->unsignedSmallInteger('reminder_minutes_before')->default(30);

            // server-computed state
            $table->timestamp('next_due_at')->nullable();
            $table->timestamp('last_check_in_at')->nullable();

            $table->string('status')->default('active'); // active, paused

            $table->timestamps();

            $table->unique(['patient_id']);
            $table->index(['next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_schedules');
    }
};
