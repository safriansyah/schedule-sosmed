<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal login per pengguna.
 *
 * Off by default and every bound optional, so an existing account behaves
 * exactly as before until a Super Admin turns the schedule on. Dates and
 * times are read in Asia/Jakarta (WIB) — see User::loginAllowedAt().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('login_schedule_enabled')->default(false)->after('is_active');
            $table->date('login_start_date')->nullable()->after('login_schedule_enabled');
            $table->date('login_end_date')->nullable()->after('login_start_date');
            $table->time('login_start_time')->nullable()->after('login_end_date');
            $table->time('login_end_time')->nullable()->after('login_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'login_schedule_enabled', 'login_start_date', 'login_end_date',
                'login_start_time', 'login_end_time',
            ]);
        });
    }
};
