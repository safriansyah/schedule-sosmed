<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Classifies messages through any OpenAI-compatible chat endpoint.
 *
 * One class covers HuggingFace Inference Providers, Groq and OpenRouter,
 * because all three speak the same POST /chat/completions shape. Switching
 * between them is three lines of .env — no new code, no new deploy. See
 * config('crm.ai.providers') for the presets.
 *
 * That portability is the point: free LLM tiers change their terms and pricing
 * often, and being able to move provider in a minute is worth more than
 * squeezing the last feature out of any one of them.
 *
 * PRIVACY and QUOTA behave exactly as in GeminiClassifier — only message text
 * is sent (see ClassificationPrompt), batched, and every failure degrades to
 * the offline verdict rather than throwing.
 */
class OpenAiCompatibleClassifier implements CommentClassifier
{
    /**
     * Rough output cost of one classified item, in tokens.
     *
     * A row is nine short fields plus a ten-word reason — measured at roughly
     * 55-65 tokens, rounded up for headroom. Used to size batches against a
     * provider's output ceiling; being a little pessimistic here is free,
     * while underestimating truncates a reply mid-JSON.
     */
    private const TOKENS_PER_ITEM = 70;

    /** Slack for the array brackets and any stray preamble. */
    private const TOKENS_OVERHEAD = 100;

    public function name(): string
    {
        return (string) $this->config('model', 'llm');
    }

    public function isAvailable(): bool
    {
        return filled($this->config('key')) && filled($this->config('base_url'));
    }

    /**
     * @param  array<string, string>  $texts
     * @return array<string, Classification>
     */
    public function classify(array $texts): array
    {
        if (! $this->isAvailable() || $texts === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($texts, $this->batchSize(), true) as $chunk) {
            $results += $this->classifyChunk($chunk);
        }

        return $results;
    }

    /**
     * Items per request — the configured batch size, reduced to whatever the
     * provider's output ceiling can actually hold.
     *
     * Groq's free tier rejects a request whose EXPECTED output exceeds its
     * per-minute limit, before running it, so asking for a big batch fails
     * outright rather than being throttled. Sizing to the ceiling turns that
     * hard failure into more, smaller requests.
     */
    private function batchSize(): int
    {
        $configured = max(1, (int) config('crm.ai.batch_size', 25));
        $ceiling = (int) $this->config('max_output_tokens', 4096);

        $affordable = intdiv(max(0, $ceiling - self::TOKENS_OVERHEAD), self::TOKENS_PER_ITEM);

        return max(1, min($configured, $affordable));
    }

    /**
     * @param  array<string, string>  $chunk
     * @return array<string, Classification>
     */
    private function classifyChunk(array $chunk): array
    {
        ['prompt' => $prompt, 'keys' => $keys] = ClassificationPrompt::build($chunk);

        return ClassificationPrompt::parse($this->call($prompt, count($chunk)), $keys, $this->name());
    }

    /** One HTTP call. Returns the assistant's text, or null on any failure. */
    private function call(string $prompt, int $chunkSize): ?string
    {
        $base = rtrim((string) $this->config('base_url'), '/');
        $model = (string) $this->config('model');

        $body = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            // Classification wants the most likely label, not a creative one.
            'temperature' => 0.1,
            // Sized to this batch rather than a blanket ceiling: providers
            // charge (and rate-limit) on what you ASK for, not just what comes
            // back.
            'max_tokens' => $this->maxTokensFor($chunkSize),
        ];

        // Not every provider or model honours JSON mode, and the ones that do
        // not reject the whole request rather than ignoring the field — so it
        // is opt-in per provider. ClassificationPrompt::parse() copes either
        // way, this just makes a clean reply more likely.
        if ($this->config('json_mode', false)) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken((string) $this->config('key'))
                ->timeout((int) $this->config('timeout', 90))
                // A rate-limited free tier needs its window to roll over, so
                // the wait grows with each attempt rather than retrying into
                // the same wall. A cold HuggingFace model also answers 503 for
                // its first few seconds — the same patience covers both.
                // Shorten it on a paid tier, where 429s are rarer and briefer.
                ->retry(3, fn (int $attempt) => $attempt * $this->backoffMs(), throw: false)
                ->post("{$base}/chat/completions", $body);
        } catch (Throwable $e) {
            Log::warning("Klasifikasi: {$model} tidak dapat dihubungi — ".$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            Log::warning(
                "Klasifikasi: {$model} menolak permintaan (".$response->status().') — '
                .mb_substr($response->body(), 0, 500)
            );

            return null;
        }

        $text = $response->json('choices.0.message.content');

        if (blank($text)) {
            Log::info("Klasifikasi: {$model} mengembalikan balasan kosong — ".mb_substr($response->body(), 0, 400));

            return null;
        }

        return $text;
    }

    /** Base wait between retries, multiplied by the attempt number. */
    private function backoffMs(): int
    {
        return max(0, (int) $this->config('retry_backoff_ms', 8000));
    }

    /** Output budget for one request of this size, never above the ceiling. */
    private function maxTokensFor(int $chunkSize): int
    {
        $ceiling = (int) $this->config('max_output_tokens', 4096);

        return min($ceiling, ($chunkSize * self::TOKENS_PER_ITEM) + self::TOKENS_OVERHEAD);
    }

    /**
     * Read a setting for the currently selected provider, falling back to the
     * preset in config/crm.php so .env only has to carry the key.
     */
    private function config(string $key, mixed $default = null): mixed
    {
        $driver = (string) config('crm.ai.driver');

        return config("crm.ai.providers.{$driver}.{$key}", $default);
    }
}
