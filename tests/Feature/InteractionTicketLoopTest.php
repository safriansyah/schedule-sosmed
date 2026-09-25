<?php

/**
 * The loop between the inbox and ticketing.
 *
 * A comment that has become a ticket is being handled — it must leave the
 * urgent queue, and come back marked done when the ticket closes. Without
 * this, the operator sees the same angry comment every morning and the red
 * badge never falls, so the queue stops meaning anything.
 */

use App\Enums\InteractionStatus;
use App\Enums\TicketStatus;
use App\Models\Interaction;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** An angry comment, exactly as the classifier would leave it. */
function urgentComment(): Interaction
{
    $interaction = instagramComment();

    $interaction->forceFill([
        'text' => 'Pelayanan UT lambat sekali, saya sudah bayar tapi tidak diproses!',
        'sentiment' => 'negative',
        'intent' => 'complaint',
        'is_urgent' => true,
        'needs_reply' => true,
        'urgency_score' => 90,
        'status' => InteractionStatus::New->value,
        'ai_classified_at' => now(),
    ])->save();

    return $interaction->refresh();
}

it('takes a comment out of the urgent queue once it becomes a ticket', function () {
    $comment = urgentComment();

    // Before: it is sitting in the queue the operator works from. Same scope
    // chain the controller uses for the tab, so the test cannot pass while the
    // real queue behaves differently.
    expect(Interaction::urgent()->open()->withoutTicket()->whereKey($comment->getKey())->exists())->toBeTrue();

    app(TicketService::class)->createFromInteraction($comment, admin());
    $comment->refresh();

    expect($comment->status)->toBe(InteractionStatus::InProgress)
        ->and($comment->first_response_at)->not->toBeNull()
        // Still urgent — it just is not waiting for anyone any more.
        ->and($comment->is_urgent)->toBeTrue()
        ->and(Interaction::urgent()->open()->withoutTicket()->whereKey($comment->getKey())->exists())->toBeFalse();
});

it('marks the comment done when its ticket is closed', function () {
    $service = app(TicketService::class);
    $comment = urgentComment();

    $ticket = $service->createFromInteraction($comment, admin());
    $service->close($ticket, 'Sudah dihubungi dan pembayarannya diproses.', admin());

    $comment->refresh();

    expect($comment->status)->toBe(InteractionStatus::Done)
        ->and($comment->resolved_at)->not->toBeNull()
        ->and($comment->resolved_by)->toBe(admin()->id);
});

it('marks the comment done when its ticket is resolved without closing', function () {
    $service = app(TicketService::class);
    $comment = urgentComment();

    $ticket = $service->createFromInteraction($comment, admin());
    $service->changeStatus($ticket, TicketStatus::Resolved, admin());

    expect($comment->refresh()->status)->toBe(InteractionStatus::Done);
});

it('puts the comment back in play when the ticket is reopened', function () {
    $service = app(TicketService::class);
    $comment = urgentComment();

    $ticket = $service->createFromInteraction($comment, admin());
    $service->close($ticket, 'Selesai.', admin());
    $service->reopen($ticket->refresh(), admin());

    $comment->refresh();

    expect($comment->status)->toBe(InteractionStatus::InProgress)
        ->and($comment->resolved_at)->toBeNull();
});

it('leaves a comment an operator already answered alone', function () {
    $service = app(TicketService::class);
    $comment = urgentComment();

    // Answered on the spot, then escalated anyway.
    $comment->forceFill(['status' => InteractionStatus::Replied->value])->save();

    $service->createFromInteraction($comment->refresh(), admin());

    // Replied is further along than InProgress; do not walk it backwards.
    expect($comment->refresh()->status)->toBe(InteractionStatus::Replied);
});

it('does not touch anything when the ticket has no comment behind it', function () {
    $ticket = app(TicketService::class)->createManual(['subject' => 'Tiket manual'], admin());

    // Nothing to sync, and nothing may blow up either.
    app(TicketService::class)->close($ticket, 'Selesai.', admin());

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->interaction_id)->toBeNull();
});

it('points follow-ups at the ticket once one exists', function () {
    $comment = urgentComment();
    $ticket = app(TicketService::class)->createFromInteraction($comment, admin());

    $response = $this->actingAs(admin())->get(route('interactions.show', $comment->refresh()));

    $response->assertOk()
        // The ticket is where the case history lives now — the comment page
        // links to it and keeps nothing of its own.
        ->assertSee($ticket->number)
        ->assertSee(route('tickets.show', $ticket), false)
        ->assertDontSee('Riwayat Follow-up');
});

it('keeps no handling controls of its own on the comment page', function () {
    $comment = urgentComment();

    // This page used to carry a follow-up form AND a status/assignee panel, so
    // a comment could accumulate a history and an owner that its ticket knew
    // nothing about. Both are gone: assignment and follow-up happen in the
    // ticket, and the comment page only routes you there.
    $this->actingAs(admin())
        ->get(route('interactions.show', $comment))
        ->assertOk()
        ->assertSee(route('tickets.fromInteraction', $comment), false)
        ->assertDontSee('Riwayat Follow-up')
        ->assertDontSee('Target respons')
        ->assertDontSee('Ditugaskan ke');

    expect(\Illuminate\Support\Facades\Route::has('interactions.update'))->toBeFalse();
});

it('drops the ticketed comment from the tab badge counts too', function () {
    $comment = urgentComment();

    $before = $this->actingAs(admin())->get(route('interactions.index', ['tab' => 'urgent']));
    $before->assertOk()->assertSee($comment->author_handle);

    app(TicketService::class)->createFromInteraction($comment, admin());

    // Gone from the queue it was in…
    $this->actingAs(admin())
        ->get(route('interactions.index', ['tab' => 'urgent']))
        ->assertOk()
        ->assertDontSee($comment->text);

    // …but never hidden outright: "Semua" still has it.
    $this->actingAs(admin())
        ->get(route('interactions.index', ['tab' => 'all']))
        ->assertOk()
        ->assertSee($comment->author_handle);
});
