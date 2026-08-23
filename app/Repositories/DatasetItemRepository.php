<?php

namespace App\Repositories;

use App\Models\Dataset;
use App\Models\DatasetItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side query builder for the dataset table: realtime search, faceted
 * filters, whitelisted sorting and pagination — all index-backed.
 */
class DatasetItemRepository
{
    private const SORTABLE = [
        'followers', 'following', 'posts', 'name',
        'username', 'platform', 'id', 'created_at',
    ];

    public function paginate(Dataset $dataset, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? config('datasets.per_page', 25));
        if (! in_array($perPage, config('datasets.per_page_options', [25]), true)) {
            $perPage = (int) config('datasets.per_page', 25);
        }

        return $this->query($dataset, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function query(Dataset $dataset, array $filters): Builder
    {
        $q = DatasetItem::query()
            ->where('dataset_id', $dataset->id)
            ->select([
                'id', 'external_id', 'name', 'username', 'platform',
                'followers', 'following', 'posts', 'is_valid',
                'is_qualified', 'profile_url', 'reason',
            ]);

        $this->applySearch($q, trim((string) ($filters['search'] ?? '')));
        $this->applyFilters($q, $filters);
        $this->applySort($q, $filters);

        return $q;
    }

    private function applySearch(Builder $q, string $term): void
    {
        if ($term === '') {
            return;
        }

        // Full-text (fast on big tables) for real words; LIKE-prefix otherwise.
        if (mb_strlen($term) >= 3 && preg_match('/[\p{L}\p{N}]{3,}/u', $term)) {
            $boolean = collect(preg_split('/\s+/', $term))
                ->filter()
                ->map(fn ($w) => '+'.preg_replace('/[+\-><()~*"@]+/', '', $w).'*')
                ->implode(' ');

            if ($boolean !== '') {
                $q->whereFullText(['name', 'username', 'external_id'], $boolean, ['mode' => 'boolean']);

                return;
            }
        }

        $q->where(function (Builder $w) use ($term) {
            $w->where('username', 'like', "{$term}%")
                ->orWhere('name', 'like', "{$term}%")
                ->orWhere('external_id', 'like', "{$term}%");
        });
    }

    private function applyFilters(Builder $q, array $filters): void
    {
        if (! empty($filters['platform'])) {
            $q->where('platform', $filters['platform']);
        }

        if (isset($filters['valid']) && $filters['valid'] !== '' && $filters['valid'] !== null) {
            $q->where('is_valid', (bool) (int) $filters['valid']);
        }

        if (isset($filters['qualified']) && $filters['qualified'] !== '' && $filters['qualified'] !== null) {
            $q->where('is_qualified', (bool) (int) $filters['qualified']);
        }

        if (isset($filters['followers_min']) && $filters['followers_min'] !== '') {
            $q->where('followers', '>=', (int) $filters['followers_min']);
        }
        if (isset($filters['followers_max']) && $filters['followers_max'] !== '') {
            $q->where('followers', '<=', (int) $filters['followers_max']);
        }

        // Named follower bucket (e.g. ">10k")
        if (! empty($filters['bucket'])) {
            [$min, $max] = $this->bucketRange($filters['bucket']);
            if ($min !== null) {
                $q->where('followers', '>=', $min);
            }
            if ($max !== null) {
                $q->where('followers', '<=', $max);
            }
        }
    }

    private function applySort(Builder $q, array $filters): void
    {
        $sort = in_array($filters['sort'] ?? '', self::SORTABLE, true)
            ? $filters['sort']
            : 'followers';

        $dir = strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $q->orderBy($sort, $dir)->orderBy('id'); // stable tiebreaker
    }

    private function bucketRange(string $bucket): array
    {
        foreach (config('datasets.follower_buckets') as $b) {
            if ($b['label'] === $bucket) {
                return [$b['min'], $b['max']];
            }
        }

        return match ($bucket) {
            '1k' => [1000, null],
            '5k' => [5000, null],
            '10k' => [10000, null],
            '50k' => [50000, null],
            '100k' => [100000, null],
            default => [null, null],
        };
    }
}
