<?php

namespace App\Services\Publishing;

use App\Enums\SocialPlatform;
use App\Models\MediaFile;
use App\Models\Schedule;
use App\Services\Publishing\Contracts\PlatformPublisher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Instagram Graph API (Instagram Login flavour, graph.instagram.com).
 *
 * Publishing is a two-step dance: create a media container, wait for Instagram
 * to finish downloading the media, then publish the container. Videos go out
 * as Reels — Instagram merged all video formats into Reels.
 */
class InstagramPublisher implements PlatformPublisher
{
    private const API = 'https://graph.instagram.com/v21.0';

    /** Poll budget while Instagram ingests the media. */
    private const MAX_POLLS = 30;

    public function platform(): SocialPlatform
    {
        return SocialPlatform::Instagram;
    }

    public function publish(Schedule $schedule, string $caption): array
    {
        $account = $schedule->account;
        $media = $schedule->content->primaryMedia();

        if (! $media) {
            throw new RuntimeException('Instagram membutuhkan gambar atau video.');
        }

        if (blank($account->access_token)) {
            throw new RuntimeException('Akun Instagram belum memiliki access token.');
        }

        $client = $this->client($account->access_token);
        $mediaUrl = $media->url();

        $creationId = $this->createContainer($client, $media, $mediaUrl, $caption);
        $this->awaitContainer($client, $creationId, $media->isVideo());

        return $this->publishContainer($client, $creationId);
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout(60)
            ->baseUrl(self::API);
    }

    /** Step 1 — hand Instagram the media URL and caption. */
    private function createContainer(PendingRequest $client, MediaFile $media, string $mediaUrl, string $caption): string
    {
        $payload = $media->isVideo()
            ? ['media_type' => 'REELS', 'video_url' => $mediaUrl, 'caption' => $caption]
            : ['image_url' => $mediaUrl, 'caption' => $caption];

        $response = $client->asForm()->post('/me/media', $payload);
        $creationId = $response->json('id');

        if ($response->failed() || blank($creationId)) {
            throw new RuntimeException($this->humanise($response->body()));
        }

        return (string) $creationId;
    }

    /** Step 2 — Instagram downloads the media asynchronously; wait for it. */
    private function awaitContainer(PendingRequest $client, string $creationId, bool $isVideo): void
    {
        $interval = $isVideo ? 5 : 2;
        $status = null;
        $body = '';

        for ($attempt = 0; $attempt < self::MAX_POLLS; $attempt++) {
            sleep($interval);

            $response = $client->get("/{$creationId}", ['fields' => 'status_code']);
            $status = $response->json('status_code');
            $body = $response->body();

            if ($status !== 'IN_PROGRESS') {
                break;
            }
        }

        if ($status !== 'FINISHED') {
            throw new RuntimeException($this->humanise($body, "Media belum siap (status: {$status})."));
        }
    }

    /** Step 3 — publish the finished container. */
    private function publishContainer(PendingRequest $client, string $creationId): array
    {
        $response = $client->asForm()->post('/me/media_publish', ['creation_id' => $creationId]);
        $externalId = $response->json('id');

        if ($response->failed() || blank($externalId)) {
            throw new RuntimeException($this->humanise($response->body()));
        }

        return [
            'external_id' => (string) $externalId,
            'permalink' => $this->permalink($client, (string) $externalId),
        ];
    }

    private function permalink(PendingRequest $client, string $externalId): ?string
    {
        $response = $client->get("/{$externalId}", ['fields' => 'permalink']);

        return $response->successful() ? $response->json('permalink') : null;
    }

    /**
     * Instagram wraps almost everything as an OAuthException, so the raw body
     * is misleading. Map the cases we actually hit to actionable Indonesian.
     */
    private function humanise(string $body, ?string $fallback = null): string
    {
        $map = [
            [['could not be retrieved', 'tidak dapat diambil', 'URI media', '2207052', '9004'],
                'Media tidak terjangkau dari internet — pastikan APP_URL memakai domain publik (bukan localhost).'],
            [['Media ID is not available', '2207027'],
                'Media masih diproses Instagram — akan dicoba lagi otomatis.'],
            [['aspect ratio', '2207009', '2207010'],
                'Rasio media tidak didukung Instagram (gunakan 4:5 sampai 1.91:1).'],
            [['cannot parse access token', 'could not be decrypted'],
                'Access token tidak terbaca — perbarui token akun ini.'],
            [['access token', 'OAuthException', '"code":190', 'expired', 'session'],
                'Access token tidak valid atau kedaluwarsa — perbarui token akun ini.'],
        ];

        foreach ($map as [$needles, $message]) {
            foreach ($needles as $needle) {
                if (stripos($body, $needle) !== false) {
                    return $message;
                }
            }
        }

        return $fallback ?? str($body)->limit(200)->toString();
    }
}
