<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the syncs resume instead of restarting.
 *
 * Both syncs used to begin at the newest post every time, which meant a run
 * repeated the work of the previous run and never reached the older posts.
 * With ~2.200 posts on the account, `accounts:sync-comments` covered the same
 * newest 40 forever — post 41 was unreachable no matter how often it ran.
 *
 * What is needed is a record of what has already been done:
 *
 *   account_media.comments_synced_at
 *     Stamped once a post's comments have been fetched. Ordering by "never
 *     fetched first" then turns each run into the next batch, and a post that
 *     genuinely has no comments is stamped too — otherwise it would sit at the
 *     head of the queue forever and block everything behind it.
 *
 *   social_accounts.media_cursor
 *     Instagram's `after` cursor for the media list. The insights walk resumes
 *     from it rather than paging from the top, which is the only way to reach
 *     posts beyond the per-run page ceiling.
 *
 *   social_accounts.media_backfilled_at
 *     Set when a walk reaches the end of the account. Distinguishes "not
 *     started" from "finished", which a null cursor alone cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_media', function (Blueprint $table) {
            $table->timestamp('comments_synced_at')->nullable()->after('posted_at');

            // The backfill query filters on this and orders by it, on a table
            // heading for tens of thousands of rows.
            $table->index(['social_account_id', 'comments_synced_at'], 'account_media_comment_progress_idx');
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            // text, not string: the cursor is an absolute URL with an opaque
            // base64 `after` token and no documented length.
            $table->text('media_cursor')->nullable()->after('meta');
            $table->timestamp('media_backfilled_at')->nullable()->after('media_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('account_media', function (Blueprint $table) {
            $table->dropIndex('account_media_comment_progress_idx');
            $table->dropColumn('comments_synced_at');
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['media_cursor', 'media_backfilled_at']);
        });
    }
};
