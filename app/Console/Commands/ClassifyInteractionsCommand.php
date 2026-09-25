<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyInteractions;
use App\Services\AI\ClassifierManager;
use App\Services\AI\GeminiClassifier;
use App\Services\AI\OpenAiCompatibleClassifier;
use Illuminate\Console\Command;

class ClassifyInteractionsCommand extends Command
{
    protected $signature = 'interactions:classify
                            {--test= : Klasifikasikan satu kalimat langsung tanpa menyentuh database}
                            {--check : Cek koneksi ke penyedia AI yang sedang dipilih}
                            {--queue : Kirim ke queue, jangan jalankan sekarang}';

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

        $this->info('Mengklasifikasikan interaksi yang belum dinilai…');

        $stats = app(ClassifyInteractions::class)->handle($classifier);

        if ($stats['classified'] === 0) {
            $this->line('Tidak ada yang perlu diklasifikasikan.');

            return self::SUCCESS;
        }

        $this->table(
            ['Diklasifikasi', 'Mendesak', 'Dari cache', 'Dari AI'],
            [[$stats['classified'], $stats['urgent'], $stats['from_cache'], $stats['from_llm']]],
        );

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
