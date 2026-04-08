<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('carers', function (Blueprint $table) {
            $table->id();

            $table->string('full_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone_e164')->nullable()->unique();

            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->string('status')->default('active'); // active, suspended, deleted

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carers');
    }
};
