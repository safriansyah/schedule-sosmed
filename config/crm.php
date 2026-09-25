<?php

/*
|--------------------------------------------------------------------------
| CRM — interaction handling & comment classification
|--------------------------------------------------------------------------
|
| Everything the inbox and the classifier need to be tuned without touching
| code. The AI section is deliberately driver-based: free LLM tiers change
| their terms often, so swapping provider must be a one-line .env change.
|
*/

return [

    /*
    | Response-time targets, in hours, measured from when the message was
    | posted to the first human response. An interaction past its target is
    | flagged in the queue and counted as an SLA breach on the dashboard.
    */
    'sla' => [
        'urgent_hours' => (int) env('CRM_SLA_URGENT_HOURS', 2),
        'normal_hours' => (int) env('CRM_SLA_NORMAL_HOURS', 24),
    ],

    'ai' => [
        /*
        | Which engine handles the ambiguous cases. The rule-based pass ALWAYS
        | runs first regardless of this setting, and remains the fallback when
        | the chosen provider is unreachable.
        |
        |   rule        offline only — no key, no quota, no network
        |   gemini      Google AI Studio
        |   groq        Groq (OpenAI-compatible)
        |   huggingface HuggingFace Inference Providers (OpenAI-compatible)
        |   openrouter  OpenRouter (OpenAI-compatible)
        */
        'driver' => env('CRM_AI_DRIVER', 'rule'),

        /*
        | Presets for the OpenAI-compatible providers. All three speak the same
        | POST /chat/completions shape, so one driver class serves them all and
        | switching is a .env change rather than a code change — which matters,
        | because free tiers change their terms regularly.
        |
        | Only the key normally needs to go in .env; model and URL default to
        | sensible values here.
        */
        'providers' => [
            'groq' => [
                'key' => env('GROQ_API_KEY'),
                'base_url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
                // Qwen, not one of the gpt-oss models: measured on real
                // comments it was the one that read Indonesian sarcasm as
                // negative rather than neutral, and it answers in compact JSON
                // instead of echoing the input back.
                //
                // Groq retires models regularly — `php artisan
                // interactions:classify --check` reports a dead model name
                // clearly, and the current list is at /openai/v1/models.
                'model' => env('GROQ_MODEL', 'qwen/qwen3.8-27b'),
                'json_mode' => true,
                'timeout' => 60,
                // Groq's free tier caps OUTPUT tokens per minute (OTPM) at
                // 1000 and rejects a request whose expected output exceeds it,
                // before running it. Left a little under the cap; the driver
                // sizes each batch to fit. Raise this on a paid tier.
                'max_output_tokens' => (int) env('GROQ_MAX_OUTPUT_TOKENS', 900),
            ],

            'huggingface' => [
                'key' => env('HUGGINGFACE_API_KEY'),
                'base_url' => env('HUGGINGFACE_URL', 'https://router.huggingface.co/v1'),
                // Must be a model served by Inference Providers, written as
                // "owner/name". Check the model page says "Inference Providers"
                // before choosing one — plain repos are not callable this way.
                'model' => env('HUGGINGFACE_MODEL', 'meta-llama/Llama-3.3-70B-Instruct'),
                // Left off: support varies by the provider HF routes to, and a
                // rejected request costs a whole batch.
                'json_mode' => false,
                // Generous: a cold model spends its first seconds loading.
                'timeout' => 120,
                'max_output_tokens' => 4096,
            ],

            'openrouter' => [
                'key' => env('OPENROUTER_API_KEY'),
                'base_url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
                'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
                'json_mode' => false,
                'timeout' => 90,
                'max_output_tokens' => 4096,
            ],
        ],

        // Comments per API call. Higher means fewer calls (and far less
        // quota), but a bigger blast radius if one response is malformed.
        'batch_size' => (int) env('CRM_AI_BATCH_SIZE', 25),

        // Max comments processed per scheduled run, so a large backlog is
        // worked through gradually instead of exhausting the daily quota in
        // one go. At ~100 comments/day this is several runs of headroom.
        'per_run_limit' => (int) env('CRM_AI_PER_RUN_LIMIT', 200),

        // Identical text is classified once and reused. Comments repeat a
        // lot ("keren kak 🔥"), so this removes most of the spend.
        'cache_days' => (int) env('CRM_AI_CACHE_DAYS', 30),

        // Below this confidence the LLM's answer is kept but the item is
        // flagged for a human to confirm.
        'low_confidence_below' => (int) env('CRM_AI_LOW_CONFIDENCE', 60),
    ],

    /*
    | Words that force `is_urgent`, whatever the model decides.
    |
    | This is the safety net: the LLM is a third-party service that can be
    | rate-limited, slow or simply wrong, and a reputational attack must never
    | slip through because a quota ran out. Matching is case-insensitive on
    | word boundaries — see RuleBasedClassifier.
    */
    'urgent_keywords' => [
        'penipuan', 'menipu', 'ditipu', 'tipu tipu', 'scam', 'bodong', 'abal-abal', 'abal abal',
        'ijazah palsu', 'ilegal', 'tidak terakreditasi', 'gak terakreditasi',
        'lapor polisi', 'laporkan', 'tuntut', 'somasi', 'pengacara',
        'viralkan', 'viralin', 'sebarkan', 'bongkar',
        'korupsi', 'pungli', 'pungutan liar', 'sogok', 'suap',
        'pelecehan', 'kekerasan', 'diskriminasi',
    ],

    /*
    | Channels the operator may create a manual interaction for, because the
    | platform gives us no usable API to read them automatically.
    */
    'manual_channels' => ['tiktok', 'whatsapp', 'facebook'],

];
