<?php

namespace App\Services\Publishing;

use App\Enums\SocialPlatform;
use App\Models\AccountMetric;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Takes a daily snapshot of every connected account's public counters.
 *
 * One row per account per day (unique constraint) is what makes the
 * today-vs-yesterday / week / month comparisons possible later on.
 */
class AccountMetricsSync
{
    /**
     * @return array{synced: int, failed: int}
     */
    public function syncAll(): array
    {
        $accounts = SocialAccount::active()->get();
        $synced = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $this->sync($account);
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning("Gagal sinkron metrik {$account->name}: ".$e->getMessage());
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    public function sync(SocialAccount $account): AccountMetric
    {
        $profile = match ($account->platform) {
            SocialPlatform::Instagram => $this->instagramProfile($account),
            default => throw new RuntimeException(
                "Sinkron metrik untuk {$account->platform->label()} belum tersedia."
            ),
        };

        // Keep the account's own snapshot fresh too, so lists stay accurate.
        $account->forceFill([
            'followers_count' => $profile['followers'],
            'media_count' => $profile['media_count'],
        ])->save();

        return AccountMetric::updateOrCreate(
            ['social_account_id' => $account->id, 'captured_at' => now()->startOfHour()],
            [
                'captured_on' => today(),
                'followers' => $profile['followers'],
                'follows' => $profile['follows'],
                'media_count' => $profile['media_count'],
            ],
        );
    }

    /** @return array{followers:int, follows:int, media_count:int} */
    private function instagramProfile(SocialAccount $account): array
    {
        if (blank($account->access_token)) {
            throw new RuntimeException('Access token belum diisi.');
        }

        $response = Http::withToken($account->access_token)
            ->timeout(20)
            ->get('https://graph.instagram.com/v21.0/me', [
                'fields' => 'followers_count,follows_count,media_count',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Instagram menolak permintaan: '.str($response->body())->limit(120));
        }

        return [
            'followers' => (int) $response->json('followers_count', 0),
            'follows' => (int) $response->json('follows_count', 0),
            'media_count' => (int) $response->json('media_count', 0),
        ];
    }
}
