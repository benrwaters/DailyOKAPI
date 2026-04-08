<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('carer_patients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('carer_id')->constrained('carers')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->string('relationship')->nullable(); // e.g. son, daughter, neighbour (optional)
            $table->boolean('is_primary')->default(true);

            $table->timestamps();

            $table->unique(['carer_id', 'patient_id']);
            $table->index(['patient_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carer_patients');
    }
};
