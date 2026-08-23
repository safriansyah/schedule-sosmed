<?php
use App\Enums\{ContentStatus, RoleName};
use App\Models\{Content, User};
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function workflow(): WorkflowService { return app(WorkflowService::class); }

it('#7 routes a verification-stage revision back to verification, not approval', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator  = User::withRole(RoleName::Curator)->firstOrFail();
    $verifier = User::withRole(RoleName::Verifier)->firstOrFail();

    $content = Content::create([
        'title' => 'Alur Revisi', 'status' => ContentStatus::Draft, 'created_by' => $creative->id,
    ]);

    workflow()->submit($content, $creative);                 // → WaitingApproval
    workflow()->approve($content->refresh(), $curator);      // → WaitingVerification, curated_by set
    workflow()->requestRevision($content->refresh(), $verifier, 'Perbaiki thumbnail'); // → Revision

    expect($content->refresh()->status)->toBe(ContentStatus::Revision);
    expect($content->curated_by)->not->toBeNull();

    // Creative fixes and resubmits — should skip re-approval and go to verification.
    workflow()->submit($content->refresh(), $creative);

    expect($content->refresh()->status)->toBe(ContentStatus::WaitingVerification);
});

it('#7 still routes an approval-stage revision back through approval', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator  = User::withRole(RoleName::Curator)->firstOrFail();

    $content = Content::create([
        'title' => 'Revisi Approval', 'status' => ContentStatus::Draft, 'created_by' => $creative->id,
    ]);

    workflow()->submit($content, $creative);                 // → WaitingApproval
    workflow()->requestRevision($content->refresh(), $curator, 'Ganti caption'); // → Revision (from approval)

    workflow()->submit($content->refresh(), $creative);

    expect($content->refresh()->status)->toBe(ContentStatus::WaitingApproval);
});

it('#8 rejects a second concurrent decision on the same content', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curatorA = User::withRole(RoleName::Curator)->firstOrFail();

    $content = Content::create([
        'title' => 'Rebutan', 'status' => ContentStatus::Draft, 'created_by' => $creative->id,
    ]);
    workflow()->submit($content, $creative);

    // Two curators loaded the same WaitingApproval row (stale copies).
    $copyA = Content::find($content->id);
    $copyB = Content::find($content->id);

    workflow()->approve($copyA, $curatorA);   // first wins → WaitingVerification

    // Second decision on the now-stale copy must be rejected by the guard.
    workflow()->reject($copyB, $curatorA, 'telat');
})->throws(RuntimeException::class);

it('#6 shows a curator only their own decisions in history', function () {
    $creative  = User::withRole(RoleName::Creative)->firstOrFail();
    $curator   = User::withRole(RoleName::Curator)->firstOrFail();

    $content = Content::create([
        'title' => 'Untuk Riwayat', 'status' => ContentStatus::Draft, 'created_by' => $creative->id,
    ]);
    workflow()->submit($content, $creative);
    workflow()->approve($content->refresh(), $curator, 'mantap');

    $this->actingAs($curator)
        ->get(route('approvals.index'))
        ->assertOk()
        ->assertSee('Riwayat keputusan saya')
        ->assertSee('Untuk Riwayat')
        ->assertSee('Disetujui');
});
