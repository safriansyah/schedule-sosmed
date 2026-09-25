<?php

namespace App\Console\Commands;

use App\Models\AccountMedia;
use App\Models\Contact;
use App\Models\SocialAccount;
use App\Models\Interaction;
use App\Services\Media\RemoteImageCache;
use Illuminate\Console\Command;

/**
 * Backfills local copies of images that are still behind live CDN links.
 *
 * The syncs cache new images as they arrive, so this is for the rows that were
 * stored before caching existed. It only helps where the signature has not yet
 * expired — an Instagram link is good for roughly a fortnight, and after that
 * the image is simply gone from our side until the next sync brings a fresh URL.
 * The command reports that honestly rather than looking like it worked.
 */
class CacheRemoteImages extends Command
{
    protected $signature = 'media:cache
                            {--limit=500 : Maksimal baris per jenis}
                            {--force : Unduh ulang meski salinan lokal sudah ada}';

    protected $description = 'Simpan salinan lokal avatar & thumbnail sebelum tautan CDN-nya kedaluwarsa';

    public function handle(RemoteImageCache $images): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');

        $done = 0;
        $expired = 0;

        foreach ($this->sources() as [$label, $rows, $folder, $keyOf, $urlOf, $column]) {
            $this->line("  <fg=cyan>{$label}</>");

            $bar = $this->output->createProgressBar($rows->count());

            foreach ($rows as $row) {
                $url = $urlOf($row);

                // Check before spending a request — the expiry is right there
                // in the URL, so a dead link is knowable without asking.
                if ($this->hasExpired($url)) {
                    $expired++;
                    $bar->advance();

                    continue;
                }

                $path = $images->store($url, $folder, (string) $keyOf($row), $force);

                if ($path) {
                    $row->forceFill([$column => $path])->save();
                    $done++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->info("Tersimpan lokal: {$done} gambar.");

        if ($expired > 0) {
            $this->warn("{$expired} tautan sudah kedaluwarsa — gambarnya tidak bisa diambil lagi.");
            $this->line('  <fg=gray>Akan kembali sendiri setelah sinkron berikutnya membawa tautan baru.</>');
        }

        return self::SUCCESS;
    }

    /** @return array<int, array{0:string,1:mixed,2:string,3:callable,4:callable,5:string}> */
    private function sources(): array
    {
        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');

        return [
            [
                'Thumbnail postingan',
                AccountMedia::whereNotNull('thumbnail_url')
                    ->when(! $force, fn ($q) => $q->whereNull('thumbnail_path'))
                    ->limit($limit)->get(),
                'thumbnails',
                fn ($m) => $m->external_id,
                fn ($m) => $m->thumbnail_url,
                'thumbnail_path',
            ],
            [
                'Avatar komentator',
                Interaction::whereNotNull('author_avatar')
                    ->when(! $force, fn ($q) => $q->whereNull('author_avatar_path'))
                    ->limit($limit)->get(),
                'avatars',
                fn ($i) => mb_strtolower((string) ($i->author_handle ?: $i->external_id)),
                fn ($i) => $i->author_avatar,
                'author_avatar_path',
            ],
            [
                'Foto profil akun',
                SocialAccount::whereNotNull('avatar_url')
                    ->when(! $force, fn ($q) => $q->whereNull('avatar_path'))
                    ->limit($limit)->get(),
                'accounts',
                fn ($a) => (string) $a->getKey(),
                fn ($a) => $a->avatar_url,
                'avatar_path',
            ],
            [
                'Avatar kontak',
                Contact::whereNotNull('avatar_url')
                    ->when(! $force, fn ($q) => $q->whereNull('avatar_path'))
                    ->limit($limit)->get(),
                'avatars',
                fn ($c) => mb_strtolower((string) ($c->identities->first()?->handle ?: $c->code)),
                fn ($c) => $c->avatar_url,
                'avatar_path',
            ],
        ];
    }

    /**
     * Instagram stamps the expiry into the link itself as `oe=`, a hex Unix
     * timestamp. Reading it costs nothing and saves a doomed request.
     */
    private function hasExpired(?string $url): bool
    {
        if (blank($url) || ! preg_match('/[?&]oe=([0-9A-Fa-f]+)/', $url, $m)) {
            return false;   // No stamp — let the request decide.
        }

        return hexdec($m[1]) < time();
    }
}
