<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('check_in_schedules', function (Blueprint $table) {
            $table->timestamp('manual_check_in_requested_at')->nullable()->after('last_check_in_at');
            $table->string('manual_check_in_request_local_date', 10)->nullable()->after('manual_check_in_requested_at');
            $table->timestamp('manual_check_in_consumed_at')->nullable()->after('manual_check_in_request_local_date');

            $table->index('manual_check_in_request_local_date', 'check_in_schedules_manual_request_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('check_in_schedules', function (Blueprint $table) {
            $table->dropIndex('check_in_schedules_manual_request_date_idx');
            $table->dropColumn([
                'manual_check_in_requested_at',
                'manual_check_in_request_local_date',
                'manual_check_in_consumed_at',
            ]);
        });
    }
};
