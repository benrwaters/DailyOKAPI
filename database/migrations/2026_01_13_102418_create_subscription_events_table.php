<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('carer_id')->constrained('carers')->cascadeOnDelete();

            $table->string('provider')->default('apple'); // apple, google
            $table->string('event_type'); // purchased, renewed, cancelled, refunded, etc

            $table->string('product_id')->nullable();
            $table->string('transaction_id')->nullable()->index();
            $table->string('original_transaction_id')->nullable()->index();

            $table->timestamp('event_time')->nullable();
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['carer_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
