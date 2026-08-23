<?php
use App\Enums\{ContentStatus, RoleName};
use App\Models\{Content, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('renders a clickable preview modal in the approval queue', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    Content::create(['title' => 'Preview Approval', 'caption' => 'Isi konten',
        'status' => ContentStatus::WaitingApproval, 'created_by' => $creative->id]);

    $html = $this->actingAs(User::withRole(RoleName::Curator)->firstOrFail())
        ->get(route('approvals.index'))->assertOk()->getContent();

    // Title now triggers the modal, not a navigation link.
    expect($html)->toContain('detailOpen = true');
    expect($html)->toContain('detailOpen: false');
    expect($html)->toContain('Halaman lengkap');   // modal footer link
    expect($html)->toContain('Preview Approval');
});

it('renders a clickable preview modal in the verification queue', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    Content::create(['title' => 'Preview Verif', 'caption' => 'Isi',
        'status' => ContentStatus::WaitingVerification, 'created_by' => $creative->id]);

    $html = $this->actingAs(User::withRole(RoleName::Verifier)->firstOrFail())
        ->get(route('verifications.index'))->assertOk()->getContent();

    expect($html)->toContain('detailOpen = true');
    expect($html)->toContain('Halaman lengkap');
    expect($html)->toContain('Preview Verif');
});
