<?php

namespace App\Console\Commands;

use App\Models\AccountMedia;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Answers one question: why is /monitoring not showing the posts that are
 * demonstrably in the database?
 *
 * The screen depends on fewer things than it looks like — `is_active` on the
 * account, and `social_account_id` on each post — so a count of 2.000 rows can
 * sit next to an empty grid with no error anywhere. Written as a command rather
 * than a set of tinker one-liners because it is run on a machine nobody can
 * look at, over the phone, in a PowerShell window where every backslash in a
 * class name is a chance to mistype.
 *
 * Read-only. It changes nothing and is safe to run at any time.
 */
class MonitoringDiagnose extends Command
{
    protected $signature = 'monitoring:diagnose';

    protected $description = 'Periksa kenapa postingan tidak tampil di halaman Monitoring';

    public function handle(): int
    {
        $total = AccountMedia::count();
        $accounts = SocialAccount::orderBy('name')->get();

        // Counted in one grouped query rather than through a relation, because
        // SocialAccount has no media() relation and this command is not the
        // place to add one.
        $perAccount = AccountMedia::selectRaw('social_account_id, COUNT(*) as total')
            ->groupBy('social_account_id')
            ->pluck('total', 'social_account_id');
        $active = $accounts->where('is_active', true);

        $this->newLine();
        $this->line('  <options=bold>AKUN</>');

        if ($accounts->isEmpty()) {
            $this->error('    Tidak ada akun sosial sama sekali.');
            $this->line('    Hubungkan akun lewat menu Akun Sosmed, lalu jalankan accounts:sync-insights.');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            $this->line(sprintf(
                '    %-24s %-8s %8s postingan   token:%s',
                '@'.$account->username,
                $account->is_active ? 'AKTIF' : 'NONAKTIF',
                number_format((int) ($perAccount[$account->id] ?? 0)),
                filled($account->access_token) ? 'ada' : 'KOSONG',
            ));
        }

        $this->newLine();
        $this->line('  <options=bold>POSTINGAN</>');
        $this->line('    total di database      : '.number_format($total));

        // The grid only ever queries the selected account, and the selected
        // account is the first ACTIVE one. Posts attached to anything else are
        // invisible no matter how many there are.
        $reachable = AccountMedia::whereIn('social_account_id', $active->pluck('id'))->count();

        $this->line('    terlihat di Monitoring : '.number_format($reachable));

        // Posts whose account row is gone — what happens when the account is
        // deleted and reconnected after a sync, since the new row gets a new id.
        $orphans = AccountMedia::whereNotIn('social_account_id', $accounts->pluck('id'))->count();

        if ($orphans > 0) {
            $this->line('    yatim (akun terhapus)  : '.number_format($orphans));
        }

        $withThumb = AccountMedia::whereNotNull('thumbnail_path')->count();
        $withMetrics = DB::table('media_metrics')->distinct()->count('account_media_id');

        $this->line('    foto tersimpan lokal   : '.number_format($withThumb).' / '.number_format($total));
        $this->line('    punya angka insight    : '.number_format($withMetrics).' / '.number_format($total));

        $this->newLine();
        $this->line('  <options=bold>KESIMPULAN</>');

        $problems = [];

        if ($active->isEmpty()) {
            $problems[] = [
                'Semua akun berstatus NONAKTIF, jadi halaman Monitoring tidak memilih akun apa pun dan tampil kosong.',
                'Aktifkan lewat menu Akun Sosmed (tombol Aktifkan), atau: php artisan tinker --execute="App\Models\SocialAccount::query()->update([\'is_active\' => true]);"',
            ];
        } elseif ($reachable === 0 && $total > 0) {
            $problems[] = [
                'Ada '.number_format($total).' postingan, tapi tidak satu pun milik akun yang aktif — kemungkinan akun dihubungkan ulang setelah sinkronisasi, sehingga id akunnya berganti.',
                'Jalankan ulang: php artisan accounts:sync-insights (postingan akan dibuat ulang untuk akun yang sekarang).',
            ];
        }

        if ($total === 0) {
            $problems[] = [
                'Belum ada postingan tersimpan.',
                'php artisan accounts:sync-insights',
            ];
        }

        if ($total > 0 && $withThumb === 0) {
            $problems[] = [
                'Tidak ada foto yang tersimpan lokal. Kartu postingan tetap muncul, tapi gambarnya kosong karena tautan CDN Instagram sudah kedaluwarsa.',
                'php artisan media:cache',
            ];
        }

        if ($problems === []) {
            $this->info('    Tidak ada masalah data — '.number_format($reachable).' postingan siap tampil.');
            $this->newLine();
            $this->line('    Buka halaman <options=bold>/monitoring</> (bukan /dashboard) dan pilih akun di bagian atas.');
            $this->line('    Kalau tetap kosong: tekan Ctrl+F5, dan pastikan filter Cari/Tipe/Sumber dalam keadaan bersih.');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($problems as $i => [$what, $how]) {
            $this->error('    '.($i + 1).'. '.$what);
            $this->line('       Perbaikan: <options=bold>'.$how.'</>');
        }

        $this->newLine();

        return self::FAILURE;
    }
}
