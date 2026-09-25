<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Classifies messages with Google Gemini, in batches.
 *
 * Gemini has its own request shape, so it gets its own driver; every
 * OpenAI-compatible provider shares OpenAiCompatibleClassifier instead. Both
 * build the instruction and read the reply through ClassificationPrompt, so
 * the labels mean the same thing whichever is configured.
 *
 * PRIVACY and QUOTA: see ClassificationPrompt — only message text is sent,
 * batched at config('crm.ai.batch_size'), and every failure returns an empty
 * result so ClassifierManager can fall back to the offline verdict.
 */
class GeminiClassifier implements CommentClassifier
{
    public function name(): string
    {
        return (string) config('services.gemini.model', 'gemini');
    }

    public function isAvailable(): bool
    {
        return filled(config('services.gemini.key'));
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

        foreach (array_chunk($texts, max(1, (int) config('crm.ai.batch_size', 25)), true) as $chunk) {
            $results += $this->classifyChunk($chunk);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $chunk
     * @return array<string, Classification>
     */
    private function classifyChunk(array $chunk): array
    {
        ['prompt' => $prompt, 'keys' => $keys] = ClassificationPrompt::build($chunk);

        return ClassificationPrompt::parse($this->call($prompt), $keys, $this->name());
    }

    /** One HTTP call. Returns the model's text, or null on any failure. */
    private function call(string $prompt): ?string
    {
        $base = rtrim((string) config('services.gemini.base_url'), '/');
        $model = (string) config('services.gemini.model');

        try {
            $response = Http::withHeaders([
                'X-goog-api-key' => (string) config('services.gemini.key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('services.gemini.timeout', 60))
                // One retry with a pause: free tiers return 429 under bursts,
                // and the next scheduled run would otherwise redo the work.
                ->retry(2, 3000, throw: false)
                ->post("{$base}/models/{$model}:generateContent", [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        // Asking for JSON directly avoids most parsing trouble;
                        // ClassificationPrompt still copes if it is ignored.
                        'responseMimeType' => 'application/json',
                        // Classification wants the most likely label, not a
                        // creative one.
                        'temperature' => 0.1,
                        'maxOutputTokens' => 8192,
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('Klasifikasi: Gemini tidak dapat dihubungi — '.$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            Log::warning(
                'Klasifikasi: Gemini menolak permintaan ('.$response->status().') — '
                .mb_substr($response->body(), 0, 500)
            );

            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (blank($text)) {
            // Usually a safety block or an empty candidate list.
            Log::info('Klasifikasi: Gemini mengembalikan balasan kosong — '.mb_substr($response->body(), 0, 400));

            return null;
        }

        return $text;
    }
}
