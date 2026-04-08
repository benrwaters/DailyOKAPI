<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->unsignedBigInteger('carer_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('category');
            $table->string('title');
            $table->text('body');
            $table->json('payload_json')->nullable();
            $table->string('status')->default('queued');
            $table->string('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['patient_id']);
            $table->index(['carer_id']);
            $table->index(['device_id']);
            $table->index(['category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notification_events');
    }
};
