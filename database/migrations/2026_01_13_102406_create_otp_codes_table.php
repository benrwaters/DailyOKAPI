<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();

            $table->string('phone_e164')->index();
            $table->string('code_hash');

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->string('purpose')->default('carer_login');

            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->timestamps();

            $table->index(['phone_e164', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
