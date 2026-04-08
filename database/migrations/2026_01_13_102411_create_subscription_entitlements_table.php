<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('carer_id')->constrained('carers')->cascadeOnDelete();

            $table->string('provider')->default('apple'); // apple, google
            $table->string('product_id')->nullable();

            $table->string('status')->default('none'); // none, active, grace, expired, revoked
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('original_transaction_id')->nullable()->index();
            $table->string('latest_transaction_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['carer_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_entitlements');
    }
};
