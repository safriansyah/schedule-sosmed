<?php

use App\Enums\Intent;
use App\Enums\Sentiment;
use App\Services\AI\ClassificationPrompt;
use App\Services\AI\OpenAiCompatibleClassifier;
use Illuminate\Support\Facades\Http;

/**
 * The OpenAI-compatible driver serves HuggingFace, Groq and OpenRouter, so it
 * is worth proving it builds the right request and survives the messy replies
 * those endpoints actually return — without needing a key for any of them.
 */
function fakeProvider(array $overrides = []): void
{
    config()->set('crm.ai.driver', 'groq');
    config()->set('crm.ai.providers.groq', array_merge([
        'key' => 'test-key-123',
        'base_url' => 'https://api.example.test/openai/v1',
        'model' => 'llama-3.3-70b-versatile',
        'json_mode' => true,
        'timeout' => 30,
        // No real waiting in tests; the backoff itself is exercised in
        // production config, not here.
        'retry_backoff_ms' => 0,
    ], $overrides));
}

/** Wrap classifier JSON the way a chat-completions endpoint returns it. */
function providerReply(string $content): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
}

it('sends a well-formed chat-completions request', function () {
    fakeProvider();

    Http::fake(['*/chat/completions' => Http::response(providerReply('[]'))]);

    app(OpenAiCompatibleClassifier::class)->classify(['a' => 'halo kak']);

    Http::assertSent(function ($request) {
        expect($request->url())->toBe('https://api.example.test/openai/v1/chat/completions');
        expect($request->hasHeader('Authorization', 'Bearer test-key-123'))->toBeTrue();

        $body = $request->data();
        expect($body['model'])->toBe('llama-3.3-70b-versatile')
            ->and($body['messages'][0]['role'])->toBe('user')
            ->and($body['temperature'])->toBe(0.1)
            ->and($body['response_format']['type'])->toBe('json_object');

        return true;
    });
});

it('never sends anything identifying about the commenter', function () {
    fakeProvider();

    Http::fake(['*/chat/completions' => Http::response(providerReply('[]'))]);

    // The caller's key is the interaction id; only the text may travel.
    app(OpenAiCompatibleClassifier::class)->classify([
        '9f8e-secret-id' => 'nomor saya 081234567890, nama Budi',
    ]);

    Http::assertSent(function ($request) {
        $prompt = $request->data()['messages'][0]['content'];

        // The message text itself goes (that is the job) but the id must not.
        expect($prompt)->not->toContain('9f8e-secret-id');

        return true;
    });
});

it('reads a clean JSON array reply', function () {
    fakeProvider();

    Http::fake(['*/chat/completions' => Http::response(providerReply(
        '[{"i":0,"sentiment":"negative","intent":"complaint","is_urgent":false,'
        .'"urgency_score":40,"lead_potential":10,"needs_reply":true,'
        .'"confidence":82,"reason":"Mengeluhkan layanan lambat"}]'
    ))]);

    $result = app(OpenAiCompatibleClassifier::class)->classify(['x' => 'lama banget diproses']);

    expect($result)->toHaveKey('x');
    expect($result['x']->sentiment)->toBe(Sentiment::Negative)
        ->and($result['x']->intent)->toBe(Intent::Complaint)
        ->and($result['x']->confidence)->toBe(82)
        ->and($result['x']->needsReply)->toBeTrue()
        ->and($result['x']->model)->toBe('llama-3.3-70b-versatile');
});

it('reads the object-wrapped shape that JSON mode forces', function () {
    fakeProvider();

    // OpenAI-style JSON mode requires the top level to be an object, so the
    // prompt asks for {"hasil": [...]}. This is the normal Groq reply.
    Http::fake(['*/chat/completions' => Http::response(providerReply(
        '{"hasil":[{"i":0,"sentiment":"negative","intent":"complaint","confidence":90}]}'
    ))]);

    $result = app(OpenAiCompatibleClassifier::class)->classify(['a' => 'lama banget']);

    expect($result['a']->sentiment)->toBe(Sentiment::Negative)
        ->and($result['a']->intent)->toBe(Intent::Complaint);
});

it('still reads a bare array from providers without JSON mode', function () {
    fakeProvider(['json_mode' => false]);

    Http::fake(['*/chat/completions' => Http::response(providerReply(
        '[{"i":0,"sentiment":"positive","intent":"praise","confidence":80}]'
    ))]);

    $result = app(OpenAiCompatibleClassifier::class)->classify(['a' => 'keren']);

    expect($result['a']->sentiment)->toBe(Sentiment::Positive);
});

it('finds the list even when the model invents its own wrapper key', function () {
    fakeProvider();

    Http::fake(['*/chat/completions' => Http::response(providerReply(
        '{"results":[{"i":0,"sentiment":"neutral","intent":"other","confidence":40}]}'
    ))]);

    $result = app(OpenAiCompatibleClassifier::class)->classify(['a' => 'hmm']);

    expect($result['a']->sentiment)->toBe(Sentiment::Neutral);
});

it('sizes the batch and the token budget to the provider ceiling', function () {
    // Groq's free tier rejects a request whose expected output exceeds its
    // per-minute limit, so a 25-item batch must be split even though the
    // configured batch size allows it.
    fakeProvider(['max_output_tokens' => 900]);
    config()->set('crm.ai.batch_size', 25);

    Http::fake(['*/chat/completions' => Http::response(providerReply('{"hasil":[]}'))]);

    $texts = [];
    for ($i = 0; $i < 25; $i++) {
        $texts["k{$i}"] = "komentar {$i}";
    }

    app(OpenAiCompatibleClassifier::class)->classify($texts);

    Http::assertSent(function ($request) {
        // (900 - 100 overhead) / 70 per item = 11 items, 11*70+100 = 870.
        expect($request->data()['max_tokens'])->toBeLessThanOrEqual(900);

        return true;
    });

    // 25 items at 11 per request = 3 calls.
    Http::assertSentCount(3);
});

it('survives a reply wrapped in a code fence with prose around it', function () {
    fakeProvider();

    Http::fake(['*/chat/completions' => Http::response(providerReply(
        "Tentu, ini hasilnya:\n```json\n"
        .'[{"i":0,"sentiment":"positive","intent":"praise","confidence":70}]'
        ."\n```"
    ))]);

    $result = app(OpenAiCompatibleClassifier::class)->classify(['y' => 'keren']);

    expect($result['y']->sentiment)->toBe(Sentiment::Positive)
        ->and($result['y']->intent)->toBe(Intent::Praise);
});

it('clamps nonsense values instead of trusting the model', function () {
    fakeProvider();

    Http::fake(['*/chat/completions' => Http::response(providerReply(
        '[{"i":0,"sentiment":"marah","intent":"tidak_ada","urgency_score":9999,'
        .'"lead_potential":-50,"confidence":"tinggi"}]'
    ))]);

    $result = app(OpenAiCompatibleClassifier::class)->classify(['z' => 'apa ini']);

    // Unknown labels fall back to the neutral defaults; numbers are clamped.
    expect($result['z']->sentiment)->toBe(Sentiment::Neutral)
        ->and($result['z']->intent)->toBe(Intent::Other)
        ->and($result['z']->urgencyScore)->toBe(100)
        ->and($result['z']->leadPotential)->toBe(0)
        ->and($result['z']->confidence)->toBe(0);
});

it('returns nothing on an API error rather than throwing', function () {
    fakeProvider();

    Http::fake(['*/chat/completions' => Http::response(['error' => 'quota exceeded'], 429)]);

    expect(app(OpenAiCompatibleClassifier::class)->classify(['a' => 'halo']))->toBe([]);
});

it('stays quiet when no key is configured', function () {
    fakeProvider(['key' => null]);

    Http::fake();

    $driver = app(OpenAiCompatibleClassifier::class);

    expect($driver->isAvailable())->toBeFalse()
        ->and($driver->classify(['a' => 'halo']))->toBe([]);

    Http::assertNothingSent();
});

it('splits a large batch into requests of the configured size', function () {
    fakeProvider();
    config()->set('crm.ai.batch_size', 5);

    Http::fake(['*/chat/completions' => Http::response(providerReply('[]'))]);

    $texts = [];
    for ($i = 0; $i < 12; $i++) {
        $texts["k{$i}"] = "komentar {$i}";
    }

    app(OpenAiCompatibleClassifier::class)->classify($texts);

    // 12 items at 5 per request = 3 calls.
    Http::assertSentCount(3);
});

it('maps answers back by index, not by order', function () {
    // The model may return rows shuffled; only "i" is authoritative.
    $built = ClassificationPrompt::build(['first' => 'satu', 'second' => 'dua']);

    $parsed = ClassificationPrompt::parse(
        '[{"i":1,"sentiment":"negative"},{"i":0,"sentiment":"positive"}]',
        $built['keys'],
        'test-model',
    );

    expect($parsed['first']->sentiment)->toBe(Sentiment::Positive)
        ->and($parsed['second']->sentiment)->toBe(Sentiment::Negative);
});
