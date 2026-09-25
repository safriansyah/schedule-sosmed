<?php

namespace App\Services\AI;

/**
 * A engine that reads messages and says what they mean.
 *
 * Implementations must be batch-first: free LLM tiers are rate-limited per
 * request, not per token, so classifying 25 comments in one call rather than
 * 25 calls is the difference between staying inside the quota and not.
 */
interface CommentClassifier
{
    /**
     * @param  array<string, string>  $texts  caller's key => message text
     * @return array<string, Classification>  keyed the same way; a key may be
     *                                        absent if that item could not be
     *                                        classified, and the caller must
     *                                        cope rather than assume a result.
     */
    public function classify(array $texts): array;

    /** Identifier stored on the interaction, e.g. 'rule' or 'gemini-flash-latest'. */
    public function name(): string;

    /** False when the engine is not usable right now (no API key, etc.). */
    public function isAvailable(): bool;
}
