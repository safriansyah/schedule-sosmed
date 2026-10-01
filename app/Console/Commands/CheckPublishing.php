<?php

namespace App\Console\Commands;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Says, before anything is scheduled, whether publishing can actually work.
 *
 * The scheduler running is not the same as content being published, and the
 * difference is easy to miss: `publish-due-content` fires every minute, the
 * queue is healthy, the token is valid — and every post still fails, because
 * of how Instagram's publishing API works.
 *
 * Instagram does not receive the image. It receives a URL and its own servers
 * download the file. On an intranet deployment that URL is something like
 * http://10.15.10.221:5566/storage/media/x.jpg, which Instagram cannot reach
 * from the internet, so the container never gets created. Nothing about that
 * is visible from inside the app; the content simply keeps failing.
 *
 * Read-only. The account check is one GET, the same call the app already makes
 * routinely. It never creates a container and never publishes anything.
 */
class CheckPublishing extends Command
{
    protected $signature = 'content:check-publish';

    protected $description = 'Periksa apakah konten terjadwal benar-benar bisa terbit ke Instagram';

    private const API = 'https://graph.instagram.com/v21.0';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>PEMERIKSAAN PENERBITAN KONTEN</>');
        $this->newLine();

        $problems = [];

        $account = SocialAccount::active()
            ->where('platform', SocialPlatform::Instagram->value)
            ->first();

        // --- 1. akun -------------------------------------------------------
        if (! $account) {
            $this->line('  [1] Akun Instagram    <fg=red>TIDAK ADA akun aktif</>');

            return $this->verdict(['Tidak ada akun Instagram aktif. Hubungkan lewat menu Akun Sosmed.']);
        }

        if (blank($account->access_token)) {
            $this->line('  [1] Akun Instagram    <fg=red>token kosong</>');

            return $this->verdict(['Token akun @'.$account->username.' kosong.']);
        }

        $this->line('  [1] Akun Instagram    <fg=green>@'.$account->username.'</>');

        // --- 2. jenis akun -------------------------------------------------
        $type = $this->accountType($account);

        if ($type === null) {
            $this->line('  [2] Jenis akun        <fg=red>tidak bisa dibaca</>');
            $problems[] = 'Token tidak bisa dipakai membaca profil — kemungkinan kedaluwarsa. '
                .'Jalankan: php artisan accounts:refresh-tokens';
        } elseif (in_array($type, ['BUSINESS', 'MEDIA_CREATOR', 'CREATOR'], true)) {
            $this->line("  [2] Jenis akun        <fg=green>{$type}</> (boleh menerbitkan)");
        } else {
            $this->line("  [2] Jenis akun        <fg=red>{$type}</>");
            $problems[] = "Akun bertipe {$type}. Instagram hanya mengizinkan penerbitan lewat API "
                .'untuk akun Bisnis atau Kreator.';
        }

        // --- 3. URL media --------------------------------------------------
        //
        // The one that actually bites. Everything else can be perfect and
        // publishing still fails here, silently, forever.
        $sample = Storage::disk('public')->url('media/contoh.jpg');
        $host = parse_url($sample, PHP_URL_HOST);

        $this->newLine();
        $this->line('  [3] URL media yang dikirim ke Instagram:');
        $this->line('      <fg=cyan>'.$sample.'</>');

        $reachable = $this->publiclyReachable($host);

        if ($reachable) {
            $this->line('      <fg=green>alamat publik</> — Instagram bisa mengunduhnya');
        } else {
            $this->line('      <fg=red>ALAMAT LOKAL</> — Instagram TIDAK bisa mengunduhnya');
            $problems[] = "Instagram mengunduh gambar SENDIRI dari URL di atas. "
                ."'{$host}' hanya bisa diakses dari jaringan lokal, jadi server Instagram "
                .'tidak akan pernah bisa mengambilnya dan konten terjadwal selalu gagal.';
        }

        // --- 4. penjadwal --------------------------------------------------
        $this->newLine();
        $this->line('  [4] Penjadwal         publish-due-content berjalan tiap menit');
        $this->line('      <fg=gray>(butuh "composer run dev:lan" tetap terbuka)</>');

        return $this->verdict($problems);
    }

    /** One read-only call — the same one the app makes when it verifies an account. */
    private function accountType(SocialAccount $account): ?string
    {
        try {
            $response = Http::withToken($account->access_token)
                ->timeout(20)
                ->get(self::API.'/me', ['fields' => 'account_type,username']);

            return $response->successful() ? $response->json('account_type') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a host is something Instagram's servers could resolve and fetch.
     *
     * A name that is not an IP is assumed public: it may still be internal DNS,
     * but a real domain is the normal case and guessing the other way would
     * warn about every correct setup.
     */
    private function publiclyReachable(?string $host): bool
    {
        if (blank($host)) {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // False for private (10.x, 172.16-31.x, 192.168.x) and reserved ranges.
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }

    /** @param array<int, string> $problems */
    private function verdict(array $problems): int
    {
        $this->newLine();

        if ($problems === []) {
            $this->info('  SIAP — konten terjadwal bisa terbit otomatis.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->error('  BELUM SIAP — konten terjadwal TIDAK akan terbit:');
        $this->newLine();

        foreach ($problems as $i => $problem) {
            $this->line('    '.($i + 1).'. '.$problem);
            $this->newLine();
        }

        $this->line('  <options=bold>Pilihan kalau server ini hanya untuk intranet:</>');
        $this->line('    a. Terbitkan manual lewat aplikasi Instagram. Sistem ini tetap dipakai');
        $this->line('       untuk merencanakan jadwal, memantau, dan menangani komentar.');
        $this->line('    b. Buka akses media ke internet (Cloudflare Tunnel / ngrok), lalu isi');
        $this->line('       APP_URL dengan alamat publik itu.');
        $this->line('    c. Pasang aplikasi di hosting publik — lihat deploy-flow.txt.');
        $this->newLine();

        return self::FAILURE;
    }
}
