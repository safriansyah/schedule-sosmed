<?php

namespace App\Services\Publishing;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Keeps long-lived Instagram tokens alive.
 *
 * An Instagram Login token lasts 60 days. Without renewal the whole app goes
 * quiet — publishing stops, metrics stop — with no error anyone would notice
 * until someone complains. Refreshing needs no extra permission, so this runs
 * daily and renews anything inside the warning window.
 */
class TokenRefresher
{
    /** Renew once the token has this many days or fewer left. */
    public const RENEW_WITHIN_DAYS = 15;

    /** Warn in the UI once the token drops below this. */
    public const WARN_WITHIN_DAYS = 14;

    /** Instagram refuses to refresh a token younger than 24 hours. */
    private const MIN_AGE_HOURS = 24;

    public function __construct(private readonly ActivityLogger $log) {}

    /**
     * @return array{refreshed: int, skipped: int, failed: int}
     */
    public function refreshAll(bool $force = false): array
    {
        $result = ['refreshed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (SocialAccount::active()->get() as $account) {
            if ($account->platform !== SocialPlatform::Instagram) {
                $result['skipped']++;
                continue;
            }

            if (! $force && ! $this->needsRefresh($account)) {
                $result['skipped']++;
                continue;
            }

            try {
                $this->refresh($account);
                $result['refreshed']++;
            } catch (Throwable $e) {
                $result['failed']++;
                Log::warning("Gagal memperbarui token {$account->name}: ".$e->getMessage());
            }
        }

        return $result;
    }

    public function refresh(SocialAccount $account): SocialAccount
    {
        if (blank($account->access_token)) {
            throw new RuntimeException('Access token belum diisi.');
        }

        $response = Http::timeout(20)->get('https://graph.instagram.com/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $account->access_token,
        ]);

        $token = $response->json('access_token');

        if ($response->failed() || blank($token)) {
            throw new RuntimeException($this->readableError($response->body()));
        }

        $expiresIn = (int) $response->json('expires_in', 0);

        $account->forceFill([
            'access_token' => $token,
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'meta' => [
                ...($account->meta ?? []),
                'permissions' => $response->json('permissions'),
                'token_refreshed_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->log->log(
            'account.token_refreshed',
            "Token {$account->name} diperbarui — berlaku sampai "
                .$account->token_expires_at?->translatedFormat('d M Y'),
            $account,
        );

        return $account->refresh();
    }

    /**
     * True when the token is close to expiry, or when we simply do not know
     * how long it has left (an account added before expiry was tracked).
     */
    public function needsRefresh(SocialAccount $account): bool
    {
        // Instagram rejects tokens younger than 24 hours. Track that from the
        // last refresh, not `updated_at` — any edit to the account would reset
        // that column and wrongly look like a brand-new token.
        $refreshedAt = $account->meta['token_refreshed_at'] ?? null;

        if ($refreshedAt && Carbon::parse($refreshedAt)->gt(now()->subHours(self::MIN_AGE_HOURS))) {
            return false;
        }

        return $account->token_expires_at === null
            || $account->token_expires_at->lt(now()->addDays(self::RENEW_WITHIN_DAYS));
    }

    private function readableError(string $body): string
    {
        return match (true) {
            str_contains($body, 'expired') => 'Token sudah kedaluwarsa — buat token baru secara manual.',
            str_contains($body, 'only be refreshed') || str_contains($body, '24 hours')
                => 'Token belum berumur 24 jam, belum bisa diperbarui.',
            str_contains($body, 'OAuthException') => 'Token ditolak Instagram — periksa kembali izin akun.',
            default => str($body)->limit(160)->toString(),
        };
    }
}
