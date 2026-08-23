<?php

namespace App\Repositories;

use App\Enums\ContentStatus;
use App\Models\Approval;
use App\Models\Content;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * All content querying lives here so controllers stay thin and every list
 * eager-loads the same relations (no N+1 in the tables).
 */
class ContentRepository
{
    private const WITH = ['creator:id,name', 'curator:id,name', 'verifier:id,name', 'media'];

    /**
     * Paginated, filtered list.
     *
     * @param  array{status?:string,q?:string,creator?:int,from?:string,to?:string}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Content awaiting a curator decision — newest submissions first. */
    public function approvalQueue(int $perPage = 12): LengthAwarePaginator
    {
        return Content::with(self::WITH)
            ->status(ContentStatus::WaitingApproval)
            ->latest('updated_at')      // most recently submitted first
            ->paginate($perPage);
    }

    /** Content awaiting the final check — newest first. */
    public function verificationQueue(int $perPage = 12): LengthAwarePaginator
    {
        return Content::with(self::WITH)
            ->status(ContentStatus::WaitingVerification)
            ->latest('updated_at')
            ->paginate($perPage);
    }

    /** Decisions this curator has already made — their own approval history. */
    public function approvalHistoryFor(User $user, int $perPage = 6): LengthAwarePaginator
    {
        return Approval::with(['content.media', 'content.creator:id,name'])
            ->where('user_id', $user->id)
            ->latest()
            ->paginate($perPage, ['*'], 'history')
            ->withQueryString();
    }

    /** Decisions this verifier has already made. */
    public function verificationHistoryFor(User $user, int $perPage = 6): LengthAwarePaginator
    {
        return Verification::with(['content.media', 'content.creator:id,name'])
            ->where('user_id', $user->id)
            ->latest()
            ->paginate($perPage, ['*'], 'history')
            ->withQueryString();
    }

    /** Counts per status — drives the dashboard cards and filter chips. */
    public function countsByStatus(): Collection
    {
        $counts = Content::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(ContentStatus::cases())
            ->mapWithKeys(fn (ContentStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]);
    }

    /** Upcoming posts for the dashboard / calendar preview. */
    public function upcoming(int $limit = 5): Collection
    {
        return Content::with(self::WITH)
            ->whereIn('status', [ContentStatus::Scheduled->value, ContentStatus::Verified->value])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();
    }

    /** Content a specific creative still has work on. */
    public function needsAttentionFor(User $user, int $limit = 5): Collection
    {
        return Content::with(self::WITH)
            ->whereIn('status', [ContentStatus::Draft->value, ContentStatus::Revision->value])
            ->createdBy($user->id)
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /** @param array<string, mixed> $filters */
    private function filtered(array $filters): Builder
    {
        return Content::with(self::WITH)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->status($status))
            ->when($filters['creator'] ?? null, fn (Builder $q, $id) => $q->where('created_by', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('scheduled_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('scheduled_at', '<=', $to))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.$term.'%';

                $q->where(fn (Builder $sub) => $sub
                    ->where('title', 'like', $like)
                    ->orWhere('caption', 'like', $like)
                    ->orWhere('hashtags', 'like', $like));
            });
    }
}
