<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Audits the settings that only start to matter once the app is reachable from
 * the internet.
 *
 * None of these produce an error on their own. The app runs perfectly with
 * APP_DEBUG=true behind a public domain — right up until somebody triggers an
 * exception and Laravel renders the stack trace, the database password and
 * every other environment value onto the page for whoever asked.
 *
 * Read-only. It changes nothing.
 */
class CheckPublicDeployment extends Command
{
    protected $signature = 'app:check-public';

    protected $description = 'Periksa pengaturan keamanan setelah aplikasi dibuka ke internet';

    public function handle(): int
    {
        $appUrl = (string) config('app.url');
        $isPublic = str_starts_with($appUrl, 'https://')
            || (! str_contains($appUrl, 'localhost') && ! $this->isPrivateHost($appUrl));

        $this->newLine();
        $this->line('  <options=bold>AUDIT DEPLOYMENT PUBLIK</>');
        $this->line('  APP_URL: <fg=cyan>'.$appUrl.'</>');
        $this->line('  Terbaca sebagai: '.($isPublic ? '<fg=yellow>bisa diakses dari internet</>' : '<fg=green>jaringan lokal saja</>'));
        $this->newLine();

        $problems = [];
        $warnings = [];

        // --- yang paling berbahaya ------------------------------------------
        if (config('app.debug')) {
            $line = '  APP_DEBUG            <fg=red>true</>';

            if ($isPublic) {
                $problems[] = [
                    'APP_DEBUG=true di situs yang bisa diakses internet. Setiap error menampilkan '
                        .'jejak kode LENGKAP beserta sandi database dan seluruh isi .env kepada siapa pun '
                        .'yang memicunya.',
                    'APP_DEBUG=false',
                ];
            } else {
                $warnings[] = 'APP_DEBUG masih true — aman untuk jaringan lokal, wajib false kalau dibuka ke internet.';
            }

            $this->line($line);
        } else {
            $this->line('  APP_DEBUG            <fg=green>false</>');
        }

        $env = (string) config('app.env');
        $this->line('  APP_ENV              '.($env === 'production' ? "<fg=green>{$env}</>" : "<fg=yellow>{$env}</>"));

        if ($isPublic && $env !== 'production') {
            $warnings[] = "APP_ENV masih '{$env}'. Sebaiknya 'production' supaya pesan error dan optimasi sesuai server.";
        }

        if (blank(config('app.key'))) {
            $problems[] = ['APP_KEY kosong — sesi dan data terenkripsi tidak aman.', 'php artisan key:generate'];
            $this->line('  APP_KEY              <fg=red>KOSONG</>');
        } else {
            $this->line('  APP_KEY              <fg=green>terisi</>');
        }

        // --- yang menentukan akses lewat IP masih bisa login ----------------
        $this->newLine();
        $this->line('  <options=bold>AKSES GANDA (domain + IP lokal)</>');

        $secure = config('session.secure');
        $domain = config('session.domain');

        if ($secure === true) {
            $this->line('  SESSION_SECURE_COOKIE <fg=red>true</>');
            $problems[] = [
                'SESSION_SECURE_COOKIE=true membuat cookie hanya dikirim lewat HTTPS, jadi akses '
                    .'lewat http://IP:5566 TIDAK BISA LOGIN — tanpa pesan error apa pun, halaman hanya '
                    .'kembali ke form login terus.',
                'Hapus baris SESSION_SECURE_COOKIE dari .env',
            ];
        } else {
            $this->line('  SESSION_SECURE_COOKIE <fg=green>tidak diset</> (benar — otomatis aman di HTTPS)');
        }

        if (filled($domain)) {
            $this->line('  SESSION_DOMAIN        <fg=red>'.$domain.'</>');
            $problems[] = [
                "SESSION_DOMAIN diisi '{$domain}', jadi cookie terikat ke domain itu dan tidak pernah "
                    .'dikirim ke alamat IP. Akses dari dalam kantor lewat IP tidak bisa login.',
                'SESSION_DOMAIN=null',
            ];
        } else {
            $this->line('  SESSION_DOMAIN        <fg=green>null</> (benar — berlaku untuk domain dan IP)');
        }

        // --- URL media ------------------------------------------------------
        $media = Storage::disk('public')->url('media/contoh.jpg');

        $this->newLine();
        $this->line('  <options=bold>URL MEDIA</> (dipakai Instagram untuk mengunduh gambar)');
        $this->line('  '.$media);

        if (str_contains(str_replace('://', '', $media), '//')) {
            $problems[] = [
                'URL media punya garis miring ganda — biasanya karena APP_URL diakhiri "/".',
                'Hapus garis miring di akhir APP_URL',
            ];
        }

        // --- hasil ----------------------------------------------------------
        $this->newLine();

        foreach ($warnings as $warning) {
            $this->line('  <fg=yellow>Catatan:</> '.$warning);
        }

        if ($problems === []) {
            if ($warnings !== []) {
                $this->newLine();
            }

            $this->info('  Tidak ada masalah pada pengaturan.');
            $this->newLine();
            $this->line('  Yang TIDAK bisa diperiksa dari sini:');
            $this->line('    - Cloudflare Access sudah dipasang? Tanpa itu, siapa pun yang tahu');
            $this->line('      alamatnya sampai ke halaman login, dan di dalamnya ada data mahasiswa.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('  ADA '.count($problems).' MASALAH:');
        $this->newLine();

        foreach ($problems as $i => [$what, $how]) {
            $this->line('    '.($i + 1).'. '.$what);
            $this->line('       Perbaikan: <options=bold>'.$how.'</>');
            $this->newLine();
        }

        $this->line('  Setelah mengubah .env: <options=bold>php artisan config:clear</>');
        $this->newLine();

        return self::FAILURE;
    }

    /** Whether the host in a URL is a private LAN address. */
    private function isPrivateHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (blank($host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return str_ends_with($host, '.local') || str_ends_with($host, '.test');
    }
}
