<?php

namespace App\Services\Publishing;

/**
 * "Sinkron sekarang" — the bounded refresh behind the button on /monitoring
 * and behind `accounts:sync-today`.
 *
 * Bounded is the whole point. The button used to call the full insights sweep,
 * which walks the media list to the page ceiling — 40 requests before a single
 * post is stored, inside a web request somebody is waiting on. On an account
 * with 2.000 posts already in the database that is a lot of work to re-do for
 * numbers that have not moved since this morning.
 *
 * So this takes the newest couple of pages and the newest few posts' comments,
 * and leaves the deep work to the schedule and to the `--lanjut` backfills.
 *
 * One class rather than the same three calls copied into a controller and a
 * command, because the moment they are copied they start to disagree about
 * what "sync now" means.
 */
class QuickSync
{
    /** Media-list pages walked. 2 × 50 = the 100 newest posts. */
    public const PAGES = 2;

    /** Posts whose comments are re-read. Comments arrive on recent posts. */
    public const COMMENT_POSTS = 10;

    public function __construct(
        private readonly AccountMetricsSync $metrics,
        private readonly InstagramInsightsSync $insights,
        private readonly InstagramCommentSync $comments,
    ) {}

    /**
     * @return array{accounts:int, media:int, snapshots:int, comments:int, failed:int}
     */
    public function run(): array
    {
        $accounts = $this->metrics->syncAll();
        $posts = $this->insights->syncAll(maxPages: self::PAGES);
        $comments = $this->comments->syncAll(maxPostsOverride: self::COMMENT_POSTS);

        return [
            'accounts' => $accounts['synced'],
            'media' => $posts['media'],
            'snapshots' => $posts['snapshots'],
            'comments' => $comments['comments'],
            'failed' => $accounts['failed'] + $posts['failed'] + $comments['failed'],
        ];
    }

    /** The sentence shown in the toast and printed by the command. */
    public function summary(array $result): string
    {
        return sprintf(
            'Sinkron selesai — %d akun, %d postingan diperiksa, %d snapshot baru, %d komentar.',
            $result['accounts'],
            $result['media'],
            $result['snapshots'],
            $result['comments'],
        );
    }
}
