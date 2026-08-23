<?php
use App\Enums\{ContentStatus, RoleName};
use App\Models\{Content, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('removes the standalone Notifikasi menu', function () {
    $this->actingAs(User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->get(route('dashboard'))->assertOk()
        ->assertDontSee('>Notifikasi<', false);
});

it('shows the approval count badge on the Approval menu for a curator', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    // Two items waiting for approval.
    Content::create(['title' => 'A1', 'status' => ContentStatus::WaitingApproval, 'created_by' => $creative->id]);
    Content::create(['title' => 'A2', 'status' => ContentStatus::WaitingApproval, 'created_by' => $creative->id]);

    $html = $this->actingAs(User::withRole(RoleName::Curator)->firstOrFail())
        ->get(route('dashboard'))->assertOk()->getContent();

    // The Approval nav link carries a badge with the count.
    expect($html)->toMatch('/Approval.*?rounded-full bg-rose-500/s');
});

it('shows the revision count badge on the Konten menu', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    Content::create(['title' => 'R1', 'status' => ContentStatus::Revision, 'created_by' => $creative->id]);

    $html = $this->actingAs(User::withRole(RoleName::Director)->firstOrFail())
        ->get(route('dashboard'))->assertOk()->getContent();

    // Konten menu shows a badge because there is content in Revision.
    expect($html)->toMatch('/Konten.*?rounded-full bg-rose-500/s');
});
