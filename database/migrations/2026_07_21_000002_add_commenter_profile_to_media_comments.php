<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer commenter details (display name, avatar, verified badge) so the
 * monitoring UI can show who commented, not just a handle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_comments', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('username');
            $table->string('avatar_url', 1024)->nullable()->after('full_name');
            $table->boolean('is_verified')->default(false)->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('media_comments', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'avatar_url', 'is_verified']);
        });
    }
};
