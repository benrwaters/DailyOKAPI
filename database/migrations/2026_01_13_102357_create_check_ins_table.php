<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->timestamp('checked_in_at');
            $table->string('type')->default('normal'); // normal, test

            // for future: device info, app version, etc.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['patient_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
