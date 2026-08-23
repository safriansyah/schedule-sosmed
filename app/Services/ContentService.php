<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContentService
{
    public function __construct(
        private readonly MediaUploadService $media,
        private readonly ActivityLogger $log,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     */
    public function create(array $data, array $files, User $actor): Content
    {
        return DB::transaction(function () use ($data, $files, $actor) {
            $content = Content::create([
                ...$this->attributes($data),
                'status' => ContentStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $this->media->attachMany($content, $files);
            $this->syncSchedules($content, $data['social_account_ids'] ?? []);

            $this->log->log(
                'content.created',
                "Draft \"{$content->title}\" dibuat",
                $content,
            );

            return $content->load('media', 'schedules');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @param  array<int, string>  $removeMediaIds
     */
    public function update(Content $content, array $data, array $files, array $removeMediaIds, User $actor): Content
    {
        return DB::transaction(function () use ($content, $data, $files, $removeMediaIds, $actor) {
            $content->update($this->attributes($data));

            foreach (MediaFile::whereIn('id', $removeMediaIds)->where('content_id', $content->id)->get() as $media) {
                $this->media->detach($media);
            }

            $this->media->attachMany($content, $files);

            if (array_key_exists('social_account_ids', $data)) {
                $this->syncSchedules($content, $data['social_account_ids'] ?? []);
            }

            $this->log->log(
                'content.updated',
                "\"{$content->title}\" diperbarui",
                $content,
                ['changed' => array_keys($content->getChanges())],
            );

            return $content->refresh()->load('media', 'schedules');
        });
    }

    public function delete(Content $content, User $actor): void
    {
        DB::transaction(function () use ($content, $actor) {
            $this->log->log(
                'content.deleted',
                "\"{$content->title}\" dihapus oleh {$actor->name}",
                $content,
            );

            $content->delete(); // soft delete — media stays for the audit trail
        });
    }

    /** Whitelist of directly writable attributes. */
    private function attributes(array $data): array
    {
        return array_filter([
            'title' => $data['title'] ?? null,
            'caption' => $data['caption'] ?? null,
            'hashtags' => $this->normaliseHashtags($data['hashtags'] ?? null),
            'mention' => $data['mention'] ?? null,
            'location' => $data['location'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'is_carousel' => $data['is_carousel'] ?? false,
        ], fn ($value) => $value !== null);
    }

    /** Ensure every tag is prefixed with exactly one "#". */
    private function normaliseHashtags(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        return collect(preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $tag) => '#'.ltrim($tag, '#'))
            ->unique()
            ->implode(' ');
    }

    /**
     * One schedule row per target account. Rows for accounts that were removed
     * are dropped, and existing ones keep their publishing history.
     *
     * @param  array<int, string>  $accountIds
     */
    private function syncSchedules(Content $content, array $accountIds): void
    {
        $accountIds = array_filter($accountIds);

        $content->schedules()
            ->whereNotIn('social_account_id', $accountIds ?: ['-'])
            ->whereNot('status', ContentStatus::Published->value)
            ->delete();

        foreach ($accountIds as $accountId) {
            $content->schedules()->updateOrCreate(
                ['social_account_id' => $accountId],
                [
                    'scheduled_at' => $content->scheduled_at ?? now(),
                    'status' => $content->status,
                    'timezone' => config('app.timezone'),
                ],
            );
        }
    }
}
