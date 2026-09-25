<?php

namespace App\Services\AI;

use App\Enums\Intent;
use App\Enums\Sentiment;

/**
 * One classifier verdict about one message.
 *
 * Immutable, and deliberately independent of Eloquent: the rule-based pass,
 * the LLM and the cache all speak this shape, which is what lets them be
 * layered and swapped without the caller caring which produced the answer.
 */
final class Classification
{
    public function __construct(
        public readonly Sentiment $sentiment,
        public readonly Intent $intent,
        public readonly bool $isUrgent,
        /** 0–100 */
        public readonly int $urgencyScore,
        /** 0–100 — how much this reads like someone who could enrol. */
        public readonly int $leadPotential,
        public readonly bool $needsReply,
        /** 0–100 — below config('crm.ai.low_confidence_below') a human should look. */
        public readonly int $confidence,
        /** Which engine decided: 'rule', 'gemini-flash-latest', … */
        public readonly string $model,
        public readonly ?string $reason = null,
    ) {}

    /**
     * Build from a decoded LLM response, clamping every field. The model is a
     * third party: assume nothing it returns is in range or even present.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $model): self
    {
        return new self(
            sentiment: Sentiment::tryFrom((string) ($data['sentiment'] ?? '')) ?? Sentiment::Neutral,
            intent: Intent::tryFrom((string) ($data['intent'] ?? '')) ?? Intent::Other,
            isUrgent: (bool) ($data['is_urgent'] ?? false),
            urgencyScore: self::clamp($data['urgency_score'] ?? 0),
            leadPotential: self::clamp($data['lead_potential'] ?? 0),
            needsReply: (bool) ($data['needs_reply'] ?? false),
            confidence: self::clamp($data['confidence'] ?? 50),
            model: $model,
            reason: filled($data['reason'] ?? null) ? mb_substr((string) $data['reason'], 0, 300) : null,
        );
    }

    /** A neutral verdict, used when every engine failed. */
    public static function unknown(string $model = 'none'): self
    {
        return new self(
            sentiment: Sentiment::Neutral,
            intent: Intent::Other,
            isUrgent: false,
            urgencyScore: 0,
            leadPotential: 0,
            needsReply: false,
            confidence: 0,
            model: $model,
        );
    }

    /**
     * Force the urgent flag on. Used by the keyword safety net, which overrides
     * the model rather than trusting it — see config('crm.urgent_keywords').
     */
    public function escalated(string $because): self
    {
        return new self(
            sentiment: Sentiment::Negative,
            intent: $this->intent === Intent::Other ? Intent::Disparagement : $this->intent,
            isUrgent: true,
            urgencyScore: max($this->urgencyScore, 90),
            leadPotential: $this->leadPotential,
            needsReply: true,
            confidence: max($this->confidence, 90),
            model: $this->model,
            reason: 'Kata kunci mendesak: '.$because,
        );
    }

    /** Columns for the `interactions` table. */
    public function toAttributes(): array
    {
        return [
            'sentiment' => $this->sentiment->value,
            'intent' => $this->intent->value,
            'is_urgent' => $this->isUrgent,
            'urgency_score' => $this->urgencyScore,
            'lead_potential' => $this->leadPotential,
            'needs_reply' => $this->needsReply,
            'ai_model' => $this->model,
            'ai_confidence' => $this->confidence,
            'ai_classified_at' => now(),
            'ai_raw' => [
                'reason' => $this->reason,
                'model' => $this->model,
                'confidence' => $this->confidence,
            ],
        ];
    }

    /** Round-trip form for the cache. */
    public function toArray(): array
    {
        return [
            'sentiment' => $this->sentiment->value,
            'intent' => $this->intent->value,
            'is_urgent' => $this->isUrgent,
            'urgency_score' => $this->urgencyScore,
            'lead_potential' => $this->leadPotential,
            'needs_reply' => $this->needsReply,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
        ];
    }

    public function isLowConfidence(): bool
    {
        return $this->confidence < (int) config('crm.ai.low_confidence_below');
    }

    private static function clamp(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }
}
