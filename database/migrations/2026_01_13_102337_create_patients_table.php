<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();

            $table->string('display_name')->nullable();

            // Which extra "easy but not brute-forceable" verification is required at login
            $table->string('verification_hint')->default('last_name'); // last_name, dob_day_month, postcode, etc
            $table->string('verification_hash'); // hash of the chosen value (never store raw)

            $table->string('status')->default('active'); // active, suspended, deleted

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
