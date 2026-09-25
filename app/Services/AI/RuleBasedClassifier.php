<?php

namespace App\Services\AI;

use App\Enums\Intent;
use App\Enums\Sentiment;

/**
 * Offline classifier: an Indonesian lexicon plus a handful of rules.
 *
 * Two jobs, and the second matters more than the first:
 *
 *  1. Answer the easy cases (obvious spam, emoji-only, a plain question) so
 *     they never cost an API call.
 *  2. Be the safety net. The urgent keyword list is enforced HERE, not by the
 *     LLM, because a reputational attack must still be flagged when the API
 *     key expires, the quota runs out or Google is having a bad day.
 *
 * Accuracy is roughly 70–80% on clear-cut messages and poor on sarcasm, which
 * is exactly why ambiguous cases are handed to the LLM instead.
 */
class RuleBasedClassifier implements CommentClassifier
{
    public function name(): string
    {
        return 'rule';
    }

    /** Always usable — no network, no key, no quota. */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param  array<string, string>  $texts
     * @return array<string, Classification>
     */
    public function classify(array $texts): array
    {
        $out = [];

        foreach ($texts as $key => $text) {
            $out[$key] = $this->classifyOne($text);
        }

        return $out;
    }

    public function classifyOne(?string $text): Classification
    {
        $normalised = $this->normalise($text);

        if ($normalised === '') {
            return new Classification(
                sentiment: Sentiment::Neutral,
                intent: Intent::Other,
                isUrgent: false,
                urgencyScore: 0,
                leadPotential: 0,
                needsReply: false,
                confidence: 95,   // an empty comment is confidently nothing
                model: $this->name(),
                reason: 'Komentar kosong atau hanya emoji.',
            );
        }

        // The hard rule runs first and short-circuits everything else.
        if ($keyword = $this->urgentKeyword($normalised)) {
            return Classification::unknown($this->name())->escalated($keyword);
        }

        if ($this->matches($normalised, Lexicon::SPAM_MARKERS)) {
            return new Classification(
                sentiment: Sentiment::Neutral,
                intent: Intent::Spam,
                isUrgent: false,
                urgencyScore: 0,
                leadPotential: 0,
                needsReply: false,
                confidence: 85,
                model: $this->name(),
                reason: 'Terdeteksi pola promosi / spam.',
            );
        }

        [$positive, $negative] = $this->polarity($normalised);

        $sentiment = match (true) {
            $positive > $negative => Sentiment::Positive,
            $negative > $positive => Sentiment::Negative,
            default => Sentiment::Neutral,
        };

        $isQuestion = $this->isQuestion($text ?? '', $normalised);
        $isComplaint = $this->matches($normalised, Lexicon::COMPLAINT_MARKERS);

        $intent = match (true) {
            $isQuestion => Intent::Question,
            $isComplaint => Intent::Complaint,
            $sentiment === Sentiment::Negative => Intent::Complaint,
            $sentiment === Sentiment::Positive => Intent::Praise,
            default => Intent::Other,
        };

        $leadPotential = $this->leadPotential($normalised, $isQuestion);

        return new Classification(
            sentiment: $sentiment,
            intent: $intent,
            isUrgent: false,
            urgencyScore: $sentiment === Sentiment::Negative ? 40 : 0,
            leadPotential: $leadPotential,
            needsReply: $intent->expectsReply(),
            confidence: $this->confidence($positive, $negative, $isQuestion),
            model: $this->name(),
            reason: $this->explain($positive, $negative, $isQuestion, $isComplaint),
        );
    }

    /* -----------------------------------------------------------------
     | Rules
     * ----------------------------------------------------------------- */

    /**
     * The forced-escalation check. Returns the matched keyword so the reason
     * shown to staff says exactly why this was raised.
     */
    public function urgentKeyword(string $normalised): ?string
    {
        foreach ((array) config('crm.urgent_keywords', []) as $keyword) {
            if ($this->contains($normalised, (string) $keyword)) {
                return (string) $keyword;
            }
        }

        return null;
    }

    /**
     * Count polarity hits, flipping a word's sign when a negator sits within
     * the two preceding tokens — "gak bagus" must not score as positive.
     *
     * @return array{0:int, 1:int} [positive, negative]
     */
    private function polarity(string $normalised): array
    {
        $tokens = explode(' ', $normalised);
        $positive = 0;
        $negative = 0;

        // Multi-word entries can't be seen token by token, so they are matched
        // against the whole string first.
        foreach (Lexicon::POSITIVE as $word) {
            if (str_contains($word, ' ') && $this->contains($normalised, $word)) {
                $positive++;
            }
        }

        foreach (Lexicon::NEGATIVE as $word) {
            if (str_contains($word, ' ') && $this->contains($normalised, $word)) {
                $negative++;
            }
        }

        foreach ($tokens as $i => $token) {
            $isPositive = in_array($token, Lexicon::POSITIVE, true);
            $isNegative = in_array($token, Lexicon::NEGATIVE, true);

            if (! $isPositive && ! $isNegative) {
                continue;
            }

            $negated = $this->negatedAt($tokens, $i);

            if ($isPositive) {
                $negated ? $negative++ : $positive++;
            } else {
                // "gak jelek" is mild praise, but weakly — don't overcount it.
                $negated ? $positive++ : $negative++;
            }
        }

        return [$positive, $negative];
    }

    /** @param array<int, string> $tokens */
    private function negatedAt(array $tokens, int $index): bool
    {
        for ($back = 1; $back <= 2; $back++) {
            $previous = $tokens[$index - $back] ?? null;

            if ($previous !== null && in_array($previous, Lexicon::NEGATORS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A question mark alone is unreliable — people ask without one and add
     * three for emphasis when they are not asking. Require either a real
     * marker word, or a "?" together with enough words to be a sentence.
     */
    private function isQuestion(string $original, string $normalised): bool
    {
        if ($this->matches($normalised, Lexicon::QUESTION_MARKERS)) {
            return true;
        }

        // A vocative alone means nothing ("mantap kakak" is praise), but a
        // vocative plus a question mark almost always is one ("min, ada?").
        if (str_contains($original, '?')) {
            return $this->matches($normalised, Lexicon::VOCATIVES)
                || str_word_count($normalised) >= 3;
        }

        return false;
    }

    private function leadPotential(string $normalised, bool $isQuestion): int
    {
        $hits = 0;

        foreach (Lexicon::LEAD_MARKERS as $marker) {
            if ($this->contains($normalised, $marker)) {
                $hits++;
            }
        }

        if ($hits === 0) {
            return $isQuestion ? 25 : 0;
        }

        // Two or more distinct intent signals ("mau daftar" + "biaya kuliah")
        // is a much stronger indicator than one.
        return min(100, 45 + ($hits * 20) + ($isQuestion ? 10 : 0));
    }

    /**
     * How much to trust this verdict. Low confidence is the signal that sends
     * a message on to the LLM, so it must be honest about weak evidence.
     */
    private function confidence(int $positive, int $negative, bool $isQuestion): int
    {
        $signals = $positive + $negative;

        $base = match (true) {
            $signals === 0 => $isQuestion ? 60 : 25,   // nothing to go on
            $signals === 1 => 50,
            default => 65,
        };

        // Mixed polarity ("bagus tapi mahal") is exactly where a lexicon fails.
        if ($positive > 0 && $negative > 0) {
            $base -= 20;
        }

        return max(0, min(100, $base));
    }

    private function explain(int $positive, int $negative, bool $isQuestion, bool $isComplaint): string
    {
        $parts = [];

        if ($positive) {
            $parts[] = "{$positive} kata positif";
        }

        if ($negative) {
            $parts[] = "{$negative} kata negatif";
        }

        if ($isQuestion) {
            $parts[] = 'terdeteksi pertanyaan';
        }

        if ($isComplaint) {
            $parts[] = 'pola keluhan layanan';
        }

        return $parts ? 'Kamus: '.implode(', ', $parts).'.' : 'Kamus: tidak ada sinyal kuat.';
    }

    /* -----------------------------------------------------------------
     | Text handling
     * ----------------------------------------------------------------- */

    /**
     * Lowercase, strip emoji and punctuation, collapse whitespace, then map
     * slang to its standard spelling so one lexicon entry covers both.
     */
    public function normalise(?string $text): string
    {
        if (blank($text)) {
            return '';
        }

        $text = mb_strtolower($text);

        // Keep letters, digits and spaces; everything else (emoji, @, #, !!!)
        // becomes a space so it can't glue two words together.
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';

        // Collapse enthusiasm: "makasihhhhhh" → "makasih", "thanksssss" →
        // "thanks". Without this, half of every friendly comment misses the
        // lexicon entirely. Three or more repeats collapse to one; two are left
        // alone because real words have them ("maaf", "keeps").
        $text = preg_replace('/(\p{L})\1{2,}/u', '$1', $text) ?? $text;

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return '';
        }

        $tokens = array_map(
            fn (string $token) => Lexicon::NORMALISE[$token] ?? $token,
            explode(' ', $text),
        );

        return implode(' ', $tokens);
    }

    /** @param array<int, string> $needles */
    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($this->contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole-word containment. Plain str_contains would match "sedih" inside
     * "kesedihan" — usually fine — but also "php" inside "phpstorm", and
     * "judi" inside longer innocent words, which is not.
     */
    private function contains(string $haystack, string $needle): bool
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '') {
            return false;
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack);
    }
}
