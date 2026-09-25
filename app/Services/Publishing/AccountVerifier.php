<?php

namespace App\Services\Publishing;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Services\Media\RemoteImageCache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Confirms an account's credentials still work and refreshes its profile
 * snapshot (username, avatar, follower count) from the platform.
 */
class AccountVerifier
{
    public function verify(SocialAccount $account): SocialAccount
    {
        if (blank($account->access_token)) {
            throw new RuntimeException('Access token belum diisi.');
        }

        return match ($account->platform) {
            SocialPlatform::Instagram => $this->verifyInstagram($account),
            default => throw new RuntimeException(
                "Verifikasi untuk {$account->platform->label()} belum tersedia."
            ),
        };
    }

    private function verifyInstagram(SocialAccount $account): SocialAccount
    {
        $response = Http::withToken($account->access_token)
            ->timeout(20)
            ->get('https://graph.instagram.com/v21.0/me', [
                'fields' => 'id,username,name,account_type,profile_picture_url,followers_count,follows_count,media_count',
            ]);

        if ($response->failed() || blank($response->json('id'))) {
            throw new RuntimeException($this->readableError($response->body()));
        }

        $data = $response->json();

        $account->forceFill([
            'external_id' => $data['id'],
            'username' => $data['username'] ?? $account->username,
            'name' => $data['name'] ?: ($data['username'] ?? $account->name),
            'avatar_url' => $avatar = $data['profile_picture_url'] ?? null,
            // force: verifying is a deliberate action, so it is also the way to
            // refresh a profile picture that has changed.
            'avatar_path' => app(RemoteImageCache::class)
                ->store($avatar, 'accounts', (string) $account->getKey(), force: true),
            'followers_count' => $data['followers_count'] ?? 0,
            'media_count' => $data['media_count'] ?? 0,
            // MERGED, not replaced. Overwriting the whole column threw away
            // `token_refreshed_at`, which TokenRefresher reads to honour
            // Instagram's "no refresh under 24 hours" rule — so every
            // verification quietly broke the next automatic renewal.
            'meta' => array_merge($account->meta ?? [], [
                'account_type' => $data['account_type'] ?? null,
                'verified_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $this->correctExpiry($account);

        return $account->refresh();
    }

    /**
     * Reconcile the stored expiry with what just happened.
     *
     * Instagram answered this token, so it is valid RIGHT NOW. If our column
     * says it lapsed, the column is wrong — and it was wrong loudly: the
     * accounts page told the user to go and mint a new token while the
     * connection test on the same screen reported success.
     *
     * Best effort, in order:
     *   1. Ask Instagram to refresh, which returns a real `expires_in`.
     *   2. If that is refused (a token under 24 hours old cannot be
     *      refreshed), clear the expiry rather than keep a date we have just
     *      disproved. "Unknown" is honest; "expired" is not.
     */
    private function correctExpiry(SocialAccount $account): void
    {
        $expiry = $account->token_expires_at;

        // A future date needs no correcting — the record already agrees with
        // reality.
        if ($expiry !== null && $expiry->isFuture()) {
            return;
        }

        try {
            app(TokenRefresher::class)->refresh($account);

            return;
        } catch (\Throwable $e) {
            report($e);
        }

        $account->forceFill([
            'token_expires_at' => null,
            'meta' => array_merge($account->meta ?? [], [
                'expiry_unknown_since' => now()->toIso8601String(),
                'expiry_note' => 'Token terbukti masih dipakai Instagram, tetapi masa berlakunya tidak bisa dipastikan.',
            ]),
        ])->save();
    }

    private function readableError(string $body): string
    {
        return match (true) {
            str_contains($body, 'Cannot parse access token') => 'Token tidak terbaca — pastikan disalin utuh tanpa spasi.',
            str_contains($body, 'expired') => 'Token sudah kedaluwarsa — buat token baru.',
            str_contains($body, 'OAuthException') => 'Token ditolak Instagram — periksa kembali token dan izin akun.',
            default => str($body)->limit(160)->toString(),
        };
    }
}
