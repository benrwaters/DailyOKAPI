<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('notifications_enabled')->default(true)->after('app_version');
            $table->timestamp('last_registered_at')->nullable()->after('notifications_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'notifications_enabled',
                'last_registered_at',
            ]);
        });
    }
};
