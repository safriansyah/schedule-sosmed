<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What "Selesai" records: how the service was carried out, how it ended,
 * which operator finished it (not necessarily whoever pressed the button),
 * and an optional note. All nullable, so entries finished before this
 * change stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_book_entries', function (Blueprint $table) {
            $table->string('service_process', 32)->nullable()->after('status');
            $table->string('resolution', 32)->nullable()->after('service_process');
            $table->foreignId('completed_by')->nullable()->after('handled_by')->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable()->after('completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('guest_book_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['service_process', 'resolution', 'completion_note']);
        });
    }
};
