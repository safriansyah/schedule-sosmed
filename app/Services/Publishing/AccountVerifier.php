<?php

namespace App\Services\Publishing;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
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
            'avatar_url' => $data['profile_picture_url'] ?? null,
            'followers_count' => $data['followers_count'] ?? 0,
            'media_count' => $data['media_count'] ?? 0,
            'meta' => ['account_type' => $data['account_type'] ?? null, 'verified_at' => now()->toIso8601String()],
        ])->save();

        return $account->refresh();
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
