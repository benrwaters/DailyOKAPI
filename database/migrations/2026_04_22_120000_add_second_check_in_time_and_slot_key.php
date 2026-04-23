<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_in_schedules', function (Blueprint $table) {
            $table->string('second_check_in_time_local')->nullable()->after('check_in_time_local');
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->string('slot_key', 32)->nullable()->after('type');
            $table->index(['patient_id', 'slot_key', 'checked_in_at'], 'check_ins_patient_slot_checked_in_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropIndex('check_ins_patient_slot_checked_in_at_idx');
            $table->dropColumn('slot_key');
        });

        Schema::table('check_in_schedules', function (Blueprint $table) {
            $table->dropColumn('second_check_in_time_local');
        });
    }
};
