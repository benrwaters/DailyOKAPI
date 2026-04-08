<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('check_ins')) {
            return;
        }

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->timestamp('checked_in_at');
            $table->string('type', 32)->default('normal');
            $table->timestamps();

            $table->index(['patient_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        // Intentionally left as a no-op because this migration only exists
        // to reconcile environments where the table was already created.
    }
};
