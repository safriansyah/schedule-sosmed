<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyInteractions;
use App\Models\Interaction;
use App\Services\AI\ClassifierManager;
use App\Services\AI\GeminiClassifier;
use App\Services\AI\OpenAiCompatibleClassifier;
use Illuminate\Console\Command;

class ClassifyInteractionsCommand extends Command
{
    protected $signature = 'interactions:classify
                            {--test= : Klasifikasikan satu kalimat langsung tanpa menyentuh database}
                            {--check : Cek koneksi ke penyedia AI yang sedang dipilih}
                            {--queue : Kirim ke queue, jangan jalankan sekarang}
                            {--putaran=1 : Jalankan beberapa batch sekaligus (1 batch = crm.ai.per_run_limit)}
                            {--jeda=5 : Jeda detik antar putaran, supaya kuota API tidak dihantam}';

    protected $description = 'Klasifikasikan komentar & DM yang belum dinilai (sentimen, intent, urgensi)';

    public function handle(ClassifierManager $classifier): int
    {
        if ($this->option('check')) {
            return $this->check();
        }

        // --test is the fastest way to sanity-check a lexicon change or an API
        // key without waiting for real comments to arrive.
        if ($text = $this->option('test')) {
            return $this->preview($classifier, (string) $text);
        }

        if ($this->option('queue')) {
            ClassifyInteractions::dispatch();
            $this->info('Job klasifikasi dikirim ke queue.');

            return self::SUCCESS;
        }

        return $this->classifyBacklog($classifier);
    }

    /**
     * Work through the backlog.
     *
     * This command has always RESUMED rather than restarted — pending() takes
     * the next `per_run_limit` rows whose ai_classified_at is still null — so
     * there is no --lanjut to add. What was missing is any sign of that: it
     * reported what one batch did and nothing about what remained, so there was
     * no way to tell whether running it again would do anything.
     *
     * --putaran chews through several batches in one go, with a pause between,
     * for the case this was really about: a few thousand comments arriving at
     * once after a fresh scrape.
     */
    private function classifyBacklog(ClassifierManager $classifier): int
    {
        $rounds = max(1, (int) $this->option('putaran'));
        $pause = max(0, (int) $this->option('jeda'));
        $perRound = max(1, (int) config('crm.ai.per_run_limit', 200));

        $pending = Interaction::unclassified()->count();

        $this->newLine();
        $this->line('  Driver     : <fg=cyan>'.config('crm.ai.driver', 'rule').'</>');
        $this->line('  Belum dinilai: '.number_format($pending));

        if ($pending === 0) {
            $this->newLine();
            $this->line('  Tidak ada yang perlu diklasifikasikan.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line(sprintf('  Rencana    : %d putaran x %s = maksimal %s interaksi',
            $rounds, number_format($perRound), number_format($rounds * $perRound)));
        $this->newLine();

        $totals = ['classified' => 0, 'urgent' => 0, 'from_cache' => 0, 'from_llm' => 0];

        for ($round = 1; $round <= $rounds; $round++) {
            $stats = app(ClassifyInteractions::class)->handle($classifier);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($stats[$key] ?? 0);
            }

            if ($rounds > 1) {
                $this->line(sprintf('    putaran %d/%d — %s dinilai (%s dari cache, %s dari AI)',
                    $round, $rounds,
                    number_format($stats['classified']),
                    number_format($stats['from_cache']),
                    number_format($stats['from_llm'])));
            }

            // Backlog exhausted: further rounds would query an empty set.
            if ($stats['classified'] === 0) {
                break;
            }

            if ($pause > 0 && $round < $rounds) {
                sleep($pause);
            }
        }

        $left = Interaction::unclassified()->count();

        $this->newLine();
        $this->table(
            ['Diklasifikasi', 'Mendesak', 'Dari cache', 'Dari AI', 'Sisa'],
            [[
                number_format($totals['classified']),
                number_format($totals['urgent']),
                number_format($totals['from_cache']),
                number_format($totals['from_llm']),
                number_format($left),
            ]],
        );

        if ($left > 0) {
            $this->line('  Lanjutkan: <options=bold>php artisan interactions:classify --putaran='
                .max(1, (int) ceil($left / $perRound)).'</>');
            $this->line('  <fg=gray>Atau biarkan saja — penjadwal menjalankannya tiap 15 menit.</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Report what is configured and whether it actually answers.
     *
     * Sends one throwaway sentence so a wrong key, a wrong model name or a
     * blocked project shows up as a clear message here rather than as silently
     * rule-only classification later.
     */
    private function check(): int
    {
        $driver = (string) config('crm.ai.driver', 'rule');

        $this->newLine();
        $this->line("  Driver aktif : <fg=cyan>{$driver}</>");

        if ($driver === 'rule') {
            $this->line('  Status       : <fg=green>siap</> — kamus offline, tanpa API');
            $this->newLine();
            $this->line('  Ganti dengan CRM_AI_DRIVER=groq|huggingface|openrouter|gemini di .env');
            $this->newLine();

            return self::SUCCESS;
        }

        $preset = config("crm.ai.providers.{$driver}");
        $key = $driver === 'gemini' ? config('services.gemini.key') : ($preset['key'] ?? null);
        $model = $driver === 'gemini' ? config('services.gemini.model') : ($preset['model'] ?? null);
        $url = $driver === 'gemini' ? config('services.gemini.base_url') : ($preset['base_url'] ?? null);

        $this->line("  Model        : <fg=cyan>{$model}</>");
        $this->line("  Endpoint     : {$url}");
        // Never print the key itself — this output gets pasted into chats.
        $this->line('  API key      : '.(filled($key) ? '<fg=green>terisi</> ('.mb_substr((string) $key, 0, 6).'…)' : '<fg=red>KOSONG</>'));

        if (blank($key)) {
            $this->newLine();
            $this->error('  Kunci API belum diisi di .env — klasifikasi akan memakai kamus saja.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Mengirim satu kalimat uji…');

        $engine = $driver === 'gemini'
            ? app(GeminiClassifier::class)
            : app(OpenAiCompatibleClassifier::class);

        $result = $engine->classify(['uji' => 'min mau tanya, pendaftaran gelombang 2 kapan ya?']);

        $this->newLine();

        if ($result === []) {
            $this->error('  GAGAL — tidak ada balasan. Detail lengkap ada di storage/logs/laravel.log');
            $this->newLine();

            return self::FAILURE;
        }

        $verdict = $result['uji'];
        $this->info('  BERHASIL — penyedia menjawab.');
        $this->line("  Hasil uji    : {$verdict->sentiment->label()} / {$verdict->intent->label()} / keyakinan {$verdict->confidence}%");
        $this->newLine();

        return self::SUCCESS;
    }

    private function preview(ClassifierManager $classifier, string $text): int
    {
        $verdict = $classifier->preview($text);

        $this->newLine();
        $this->line('  <fg=gray>Teks:</> '.$text);
        $this->newLine();

        $this->table(['Aspek', 'Hasil'], [
            ['Sentimen', $verdict->sentiment->label()],
            ['Intent', $verdict->intent->label()],
            ['Mendesak', $verdict->isUrgent ? 'YA' : 'tidak'],
            ['Skor urgensi', $verdict->urgencyScore],
            ['Potensi lead', $verdict->leadPotential],
            ['Perlu dibalas', $verdict->needsReply ? 'ya' : 'tidak'],
            ['Keyakinan', $verdict->confidence.'%'],
            ['Mesin', $verdict->model],
            ['Alasan', $verdict->reason ?? '—'],
        ]);

        return self::SUCCESS;
    }
}
