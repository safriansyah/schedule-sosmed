<?php
use App\Enums\{ContentStatus, RoleName, SocialPlatform};
use App\Models\{Content, SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('hides menus that a role cannot reach', function () {
    // Creative should not see admin menus in the sidebar
    $this->actingAs(User::withRole(RoleName::Creative)->firstOrFail())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('>Pengguna<', false)       // users menu
        ->assertDontSee('>Approval<', false)        // approval queue
        ->assertDontSee('>Akun Sosmed<', false);

    // Director sees monitoring/approval-view menus but is read-only
    $this->actingAs(User::withRole(RoleName::Director)->firstOrFail())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('>Pengguna<', false)
        ->assertSee('>Approval<', false);
});

it('hides decision buttons from a read-only director in the queue', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $director = User::withRole(RoleName::Director)->firstOrFail();

    Content::create([
        'title' => 'Antre', 'status' => ContentStatus::WaitingApproval, 'created_by' => $creative->id,
    ]);

    // Director can open the queue…
    $response = $this->actingAs($director)->get(route('approvals.index'))->assertOk();
    // …but sees "Hanya lihat", not the decision buttons
    $response->assertSee('Hanya lihat')->assertDontSee('Beri Keputusan');

    // Curator sees the real decision trigger
    $this->actingAs(User::withRole(RoleName::Curator)->firstOrFail())
        ->get(route('approvals.index'))->assertOk()->assertSee('Beri Keputusan');
});

it('lets the owner cancel their in-flight content', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    $content = Content::create([
        'title' => 'Salah Kirim', 'status' => ContentStatus::WaitingApproval, 'created_by' => $creative->id,
    ]);

    // Owner could not cancel before (update policy blocked non-draft); now they can.
    expect($creative->can('cancel', $content))->toBeTrue();

    $this->actingAs($creative)->post(route('contents.cancel', $content))->assertRedirect();
    expect($content->fresh()->status)->toBe(ContentStatus::Cancelled);
});

it('lets the owner retry their own failed content', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'flow-acc'],
        ['name' => 'Flow IG', 'access_token' => 'x', 'is_active' => true],
    );

    $content = Content::create([
        'title' => 'Gagal', 'status' => ContentStatus::Failed, 'created_by' => $creative->id,
        'scheduled_at' => now()->subHour(), 'last_error' => 'error',
    ]);
    $content->schedules()->create([
        'social_account_id' => $account->id, 'scheduled_at' => now()->subHour(),
        'status' => ContentStatus::Failed, 'attempts' => 5,
    ]);

    // Previously only super admin could retry — now the owner can.
    expect($creative->can('retry', $content))->toBeTrue();

    $this->actingAs($creative)->post(route('contents.retry', $content))->assertRedirect();
    expect($content->fresh()->status)->toBe(ContentStatus::Scheduled);
});

it('drops the orphan Approved status from the pipeline', function () {
    $values = array_map(fn ($s) => $s->value, ContentStatus::pipeline());
    expect($values)->not->toContain('approved');
});
