<?php

namespace App\Services\Datasets;

/**
 * Maps a heterogeneous source row onto the normalised dataset_items schema.
 *
 * Supported aliases cover both observed shapes:
 *   - flat array rows:  nama / username / posts / url ...
 *   - wrapped rows:      full_name / posts_count / profile_url ...
 *
 * Anything not mapped is preserved verbatim in `extra` so no information is
 * lost and the schema stays future-proof without migrations.
 */
class JsonItemNormalizer
{
    /** Source keys consumed into typed columns (excluded from `extra`). */
    private const CONSUMED = [
        'nama', 'full_name', 'name', 'account_name',
        'username', 'user', 'handle',
        'platform', 'source',
        'followers', 'follower_count', 'followers_count',
        'following', 'following_count',
        'posts', 'posts_count', 'post_count', 'media_count',
        'valid', 'is_valid',
        'qualified', 'is_qualified',
        'nim', 'id', 'external_id',
        'url', 'profile_url', 'link',
        'reason',
    ];

    public function normalize(array $row, int $datasetId, string $now): array
    {
        $extra = array_diff_key($row, array_flip(self::CONSUMED));

        return [
            'dataset_id' => $datasetId,
            'external_id' => $this->str($row['nim'] ?? $row['external_id'] ?? $row['id'] ?? null, 191),
            'name' => $this->str(
                $row['nama'] ?? $row['full_name'] ?? $row['name'] ?? $row['account_name'] ?? null, 191
            ),
            'username' => $this->str($row['username'] ?? $row['user'] ?? $row['handle'] ?? null, 191),
            'platform' => $this->str(
                strtolower(trim((string) ($row['platform'] ?? $row['source'] ?? ''))) ?: null, 64
            ),
            'followers' => $this->int($row['followers'] ?? $row['follower_count'] ?? $row['followers_count'] ?? 0),
            'following' => $this->int($row['following'] ?? $row['following_count'] ?? 0),
            'posts' => $this->int(
                $row['posts'] ?? $row['posts_count'] ?? $row['post_count'] ?? $row['media_count'] ?? 0
            ),
            'is_valid' => $this->bool($row['valid'] ?? $row['is_valid'] ?? false),
            'is_qualified' => $this->bool($row['qualified'] ?? $row['is_qualified'] ?? false),
            'profile_url' => $this->str($row['url'] ?? $row['profile_url'] ?? $row['link'] ?? null, 512),
            'reason' => $this->str($row['reason'] ?? null, 191),
            'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function int(mixed $v): int
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }

        return max(0, (int) preg_replace('/[^\d\-]/', '', (string) $v));
    }

    private function bool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'y', 'ok', 'valid'], true);
    }

    private function str(mixed $v, int $max): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return mb_substr(trim((string) $v), 0, $max);
    }
}
