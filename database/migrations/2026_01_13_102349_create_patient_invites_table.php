<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('patient_invites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('carer_id')->constrained('carers')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->string('code')->unique(); // short code e.g. A7K2
            $table->boolean('is_active')->default(true);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->timestamps();

            $table->index(['carer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_invites');
    }
};
