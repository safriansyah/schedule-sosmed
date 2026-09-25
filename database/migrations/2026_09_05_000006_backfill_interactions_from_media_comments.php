<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves every existing Instagram comment into the unified inbox.
 *
 * `media_comments` is deliberately LEFT IN PLACE and untouched. It costs
 * nothing to keep, and if anything about the new inbox turns out wrong the
 * original rows are still there to replay from. It can be dropped in a later
 * migration once the new table has been in production for a while.
 *
 * Chunked because an account with a long history can hold tens of thousands of
 * comments, and building one giant insert would exhaust memory on shared
 * hosting.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs have no legacy table to read from.
        if (! DB::getSchemaBuilder()->hasTable('media_comments')) {
            return;
        }

        $now = now();

        DB::table('media_comments')->orderBy('id')->chunk(500, function ($comments) use ($now) {
            $rows = [];

            foreach ($comments as $comment) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'channel' => 'instagram',
                    'type' => 'comment',
                    'direction' => 'inbound',
                    'contact_id' => null,       // resolved by contacts:resolve afterwards
                    'source_type' => \App\Models\AccountMedia::class,
                    'source_id' => $comment->account_media_id,
                    'external_id' => $comment->external_id,
                    'author_handle' => $comment->username,
                    'author_name' => $comment->full_name,
                    'author_avatar' => $comment->avatar_url,
                    'author_verified' => $comment->is_verified,
                    'text' => $comment->text,
                    'like_count' => $comment->like_count,
                    'reply_count' => $comment->reply_count,
                    'occurred_at' => $comment->commented_at,
                    'status' => 'new',
                    'created_at' => $comment->created_at ?? $now,
                    'updated_at' => $comment->updated_at ?? $now,
                ];
            }

            // insertOrIgnore, not insert: the unique (channel, external_id) key
            // makes re-running this migration a no-op rather than a crash.
            if ($rows) {
                DB::table('interactions')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        DB::table('interactions')->where('channel', 'instagram')->where('type', 'comment')->delete();
    }
};
