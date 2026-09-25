<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copy of the connected account's profile picture.
 *
 * `avatar_url` was only ever written at verification time, so it expired a few
 * days later and nothing refreshed it — the account picture has been a broken
 * image ever since. See RemoteImageCache for why the URL alone is not enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
