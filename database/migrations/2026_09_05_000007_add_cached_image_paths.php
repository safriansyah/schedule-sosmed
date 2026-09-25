<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copies of images that live behind expiring CDN links.
 *
 * The remote URL columns are kept: they are still what a fresh sync writes,
 * and they are the source the cache downloads from. These columns simply say
 * "we also have our own copy, use that instead".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_media', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('thumbnail_url');
        });

        Schema::table('interactions', function (Blueprint $table) {
            $table->string('author_avatar_path')->nullable()->after('author_avatar');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('account_media', fn (Blueprint $t) => $t->dropColumn('thumbnail_path'));
        Schema::table('interactions', fn (Blueprint $t) => $t->dropColumn('author_avatar_path'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('avatar_path'));
    }
};
