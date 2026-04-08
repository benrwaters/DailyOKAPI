<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type'); // carer, patient
            $table->unsignedBigInteger('owner_id');

            $table->string('platform')->default('ios'); // ios, android
            $table->string('push_token')->unique(); // APNs or FCM token

            $table->string('device_model')->nullable();
            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();

            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
