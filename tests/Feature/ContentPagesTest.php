<?php
use App\Enums\{RoleName, SocialPlatform};
use App\Models\{SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('renders every workflow page for the right role', function () {
    SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'page-test'],
        ['name' => 'IG Utama', 'username' => 'ig_utama', 'is_active' => true],
    );

    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator  = User::withRole(RoleName::Curator)->firstOrFail();
    $verifier = User::withRole(RoleName::Verifier)->firstOrFail();
    $admin    = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($creative)->get(route('contents.index'))->assertOk()->assertSee('Konten');
    $this->actingAs($creative)->get(route('contents.create'))->assertOk()->assertSee('Akun Tujuan');
    $this->actingAs($curator)->get(route('approvals.index'))->assertOk()->assertSee('Antrean Approval');
    $this->actingAs($verifier)->get(route('verifications.index'))->assertOk()->assertSee('Antrean Verifikasi');
    $this->actingAs($admin)->get(route('activities.index'))->assertOk()->assertSee('Log Aktivitas');
});

it('hides queues from roles that may not see them', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    $this->actingAs($creative)->get(route('approvals.index'))->assertForbidden();
    $this->actingAs($creative)->get(route('verifications.index'))->assertForbidden();
    $this->actingAs($creative)->get(route('activities.index'))->assertForbidden();
});
