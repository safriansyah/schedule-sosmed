<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor's own verdict on a finished visit: 1–5 stars and an optional
 * review, given once, by the visitor (through a signed link — they have no
 * account), after the visit is done or its ticket is resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_book_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('completion_note');
            $table->text('review')->nullable()->after('rating');
            $table->timestamp('rated_at')->nullable()->after('review');

            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::table('guest_book_entries', function (Blueprint $table) {
            $table->dropIndex(['rating']);
            $table->dropColumn(['rating', 'review', 'rated_at']);
        });
    }
};
