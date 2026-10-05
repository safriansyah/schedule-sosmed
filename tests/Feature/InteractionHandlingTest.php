<?php

/**
 * Interactions: grouped per account, followed up again and again, Closed —
 * and never deleted along the way.
 */

use App\Enums\InteractionStatus;
use App\Enums\InteractionType;
use App\Enums\RoleName;
use App\Enums\SocialPlatform;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function commentFrom(string $handle, array $attributes = []): Interaction
{
    return Interaction::create(array_merge([
        'channel' => SocialPlatform::Instagram->value,
        'type' => InteractionType::Comment->value,
        'direction' => 'inbound',
        'external_id' => 'grp-'.uniqid('', true),
        'author_handle' => $handle,
        'author_name' => 'Uji '.$handle,
        'text' => 'komentar uji',
        'occurred_at' => now()->subHour(),
        'status' => InteractionStatus::New->value,
    ], $attributes));
}

function inboxOperator(): User
{
    return User::withRole(RoleName::Operator)->firstOrFail();
}

it('groups one account\'s comments into a single row', function () {
    $handle = 'grupuji'.substr(uniqid(), -6);

    foreach (range(1, 12) as $i) {
        commentFrom($handle, ['occurred_at' => now()->subMinutes($i)]);
    }

    $this->actingAs(inboxOperator())
        ->get(route('interactions.index', ['tab' => 'all', 'view' => 'account', 'q' => $handle]))
        ->assertOk()
        ->assertSee('@'.$handle)
        ->assertSee('Total Interactions')
        ->assertSee('Open · 12');

    $this->actingAs(inboxOperator())
        ->get(route('interactions.account', ['channel' => 'instagram', 'handle' => $handle]))
        ->assertOk()
        ->assertSee('komentar uji');
});

it('follows up again and again, then closes without deleting anything', function () {
    $interaction = commentFrom('ikutiuji');
    $operator = inboxOperator();

    foreach (['Komentar pertama dijawab', 'Dia membalas, dijawab lagi', 'Pertanyaan baru, dijawab'] as $note) {
        $this->actingAs($operator)->post(route('interactions.followUp', $interaction), [
            'action' => 'dibalas', 'response_text' => $note,
        ])->assertSessionHasNoErrors();
    }

    expect($interaction->refresh()->status)->toBe(InteractionStatus::InProgress)
        ->and($interaction->followUps()->count())->toBe(3);

    $this->actingAs($operator)->post(route('interactions.close', $interaction))->assertRedirect();

    $interaction->refresh();

    expect($interaction->status)->toBe(InteractionStatus::Closed)
        ->and($interaction->resolved_by)->toBe($operator->id)
        ->and(Interaction::whereKey($interaction->id)->exists())->toBeTrue()
        ->and(Interaction::whereKey($interaction->id)->open()->exists())->toBeFalse()
        ->and($interaction->followUps()->count())->toBe(3);

    // History is still on the page.
    $this->actingAs($operator)->get(route('interactions.show', $interaction))
        ->assertOk()
        ->assertSee('Dia membalas, dijawab lagi')
        ->assertSee('Buka Kembali');

    // A new follow-up opens it again.
    $this->actingAs($operator)->post(route('interactions.followUp', $interaction), [
        'action' => 'dibalas', 'response_text' => 'Ditanya lagi minggu depannya',
    ]);

    expect($interaction->refresh()->status)->toBe(InteractionStatus::InProgress)
        ->and($interaction->followUps()->count())->toBe(4);
});

it('can close together with the last follow-up, and reopen', function () {
    $interaction = commentFrom('tutupuji');

    $this->actingAs(inboxOperator())->post(route('interactions.followUp', $interaction), [
        'action' => 'dibalas', 'response_text' => 'Selesai dijawab', 'close' => '1',
    ]);

    expect($interaction->refresh()->status)->toBe(InteractionStatus::Closed);

    $this->actingAs(inboxOperator())->post(route('interactions.reopen', $interaction));

    expect($interaction->refresh()->status)->toBe(InteractionStatus::InProgress)
        ->and($interaction->resolved_at)->toBeNull();
});

it('closes every open interaction of one account at once', function () {
    $handle = 'semuauji'.substr(uniqid(), -6);
    $a = commentFrom($handle);
    $b = commentFrom($handle);
    $other = commentFrom('oranglain'.substr(uniqid(), -6));

    $this->actingAs(inboxOperator())->post(route('interactions.account.close'), [
        'channel' => 'instagram', 'handle' => $handle,
    ])->assertRedirect();

    expect($a->refresh()->status)->toBe(InteractionStatus::Closed)
        ->and($b->refresh()->status)->toBe(InteractionStatus::Closed)
        ->and($other->refresh()->status)->toBe(InteractionStatus::New);
});

it('lists Closed under Selesai and offers Close as a bulk action', function () {
    $interaction = commentFrom('bulkuji');

    $this->actingAs(inboxOperator())->post(route('interactions.bulk'), [
        'action' => 'close', 'ids' => [$interaction->id],
    ])->assertRedirect();

    expect($interaction->refresh()->status)->toBe(InteractionStatus::Closed);
});

it('keeps follow-up and close for roles that handle the inbox', function () {
    $interaction = commentFrom('izinuji');

    $this->actingAs(User::withRole(RoleName::Creative)->firstOrFail())
        ->post(route('interactions.close', $interaction))
        ->assertForbidden();

    expect($interaction->refresh()->status)->toBe(InteractionStatus::New);
});
