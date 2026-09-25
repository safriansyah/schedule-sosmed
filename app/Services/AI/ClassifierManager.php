<?php

namespace App\Services\AI;

use App\Models\Interaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs the two-layer classification and writes the verdicts back.
 *
 * The order is deliberate and each step exists for a reason:
 *
 *   1. Rule-based pass on everything. Free, instant, and settles the obvious
 *      cases (spam, emoji-only, hard urgent keywords).
 *   2. Cache lookup. Identical text is classified once, ever. Comments repeat
 *      constantly ("keren kak"), so this removes most of the API spend.
 *   3. LLM on what is left — only the genuinely ambiguous items, batched.
 *   4. Keyword safety net re-applied LAST, so a model that missed a
 *      reputational attack cannot un-flag it.
 *
 * Every step degrades rather than fails: if the LLM is unreachable the
 * rule-based verdict stands and the item is simply marked with a lower
 * confidence for a human to review.
 */
class ClassifierManager
{
    public function __construct(
        private readonly RuleBasedClassifier $rules,
        private readonly GeminiClassifier $gemini,
        private readonly OpenAiCompatibleClassifier $openAi,
    ) {}

    /**
     * The engine for the ambiguous cases, chosen by config('crm.ai.driver').
     *
     * Returns null when the driver is 'rule', or when the configured provider
     * has no key — in both cases the offline verdict simply stands.
     */
    private function remote(): ?CommentClassifier
    {
        $driver = (string) config('crm.ai.driver', 'rule');

        $engine = match ($driver) {
            'gemini' => $this->gemini,
            'groq', 'huggingface', 'openrouter' => $this->openAi,
            default => null,   // 'rule' or anything unrecognised
        };

        if ($engine === null) {
            return null;
        }

        if (! $engine->isAvailable()) {
            // Logged once per run, not per item — this is a configuration
            // problem, and repeating it a hundred times buries the rest.
            Log::warning("Klasifikasi: kunci API untuk driver '{$driver}' belum diisi — memakai kamus saja.");

            return null;
        }

        return $engine;
    }

    /**
     * Classify a batch of interactions and persist the results.
     *
     * @param  Collection<int, Interaction>  $interactions
     * @return array{classified:int, urgent:int, from_cache:int, from_llm:int}
     */
    public function handle(Collection $interactions): array
    {
        $stats = ['classified' => 0, 'urgent' => 0, 'from_cache' => 0, 'from_llm' => 0];

        $interactions = $interactions->filter(fn (Interaction $i) => filled($i->text));

        if ($interactions->isEmpty()) {
            return $stats;
        }

        /** @var array<string, Classification> $verdicts keyed by interaction id */
        $verdicts = [];
        /** @var array<string, string> $needsLlm id => text */
        $needsLlm = [];

        // ---- 1 & 2: offline pass, then cache -------------------------------
        foreach ($interactions as $interaction) {
            $offline = $this->rules->classifyOne($interaction->text);
            $verdicts[$interaction->id] = $offline;

            // A hard keyword hit or confident spam call is final — never spend
            // an API call re-deciding something the rules already settled.
            if ($offline->isUrgent || ! $offline->isLowConfidence()) {
                continue;
            }

            if ($cached = $this->fromCache($interaction->text)) {
                $verdicts[$interaction->id] = $cached;
                $stats['from_cache']++;

                continue;
            }

            $needsLlm[$interaction->id] = $interaction->text;
        }

        // ---- 3: the ambiguous remainder ------------------------------------
        if ($needsLlm !== [] && ($engine = $this->remote()) !== null) {
            foreach ($engine->classify($needsLlm) as $id => $verdict) {
                $verdicts[$id] = $verdict;
                $stats['from_llm']++;
                $this->store($needsLlm[$id], $verdict);
            }
        }

        // ---- 4: safety net, then write -------------------------------------
        foreach ($interactions as $interaction) {
            $verdict = $this->enforceKeywords($interaction->text, $verdicts[$interaction->id]);

            $interaction->forceFill($verdict->toAttributes())->save();

            $stats['classified']++;

            if ($verdict->isUrgent) {
                $stats['urgent']++;
            }
        }

        return $stats;
    }

    /** Classify one message without touching the database — used by previews and tests. */
    public function preview(string $text): Classification
    {
        $offline = $this->rules->classifyOne($text);

        if ($offline->isUrgent || ! $offline->isLowConfidence()) {
            return $this->enforceKeywords($text, $offline);
        }

        $engine = $this->remote();

        if ($engine === null) {
            return $this->enforceKeywords($text, $offline);
        }

        $verdict = $engine->classify(['preview' => $text])['preview'] ?? $offline;

        return $this->enforceKeywords($text, $verdict);
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Re-apply the configured urgent keywords over whatever the model decided.
     *
     * This runs last on purpose. The LLM is a best-effort third party; the
     * keyword list is the thing the team controls and is accountable for.
     */
    private function enforceKeywords(string $text, Classification $verdict): Classification
    {
        if ($verdict->isUrgent) {
            return $verdict;
        }

        $keyword = $this->rules->urgentKeyword($this->rules->normalise($text));

        return $keyword ? $verdict->escalated($keyword) : $verdict;
    }

    private function fromCache(string $text): ?Classification
    {
        $cached = Cache::get($this->cacheKey($text));

        if (! is_array($cached)) {
            return null;
        }

        return Classification::fromArray($cached, $cached['model'] ?? 'cache');
    }

    private function store(string $text, Classification $verdict): void
    {
        Cache::put(
            $this->cacheKey($text),
            $verdict->toArray() + ['model' => $verdict->model],
            now()->addDays((int) config('crm.ai.cache_days', 30)),
        );
    }

    /**
     * Hash the normalised text, not the raw text: "Keren bgt!!!" and "keren
     * banget" then share one cache entry, which is the whole point.
     */
    private function cacheKey(string $text): string
    {
        return 'crm.ai.'.sha1($this->rules->normalise($text));
    }
}
