<?php
use App\Enums\{ContentStatus, RoleName, SocialPlatform};
use App\Models\{Activity, Content, SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('public');

    $this->creative = User::withRole(RoleName::Creative)->firstOrFail();
    $this->curator  = User::withRole(RoleName::Curator)->firstOrFail();
    $this->verifier = User::withRole(RoleName::Verifier)->firstOrFail();
    $this->director = User::withRole(RoleName::Director)->firstOrFail();

    $this->account = SocialAccount::create([
        'platform' => SocialPlatform::Instagram,
        'name' => 'Test IG', 'username' => 'test_ig',
        'external_id' => 'ig-'.uniqid(), 'is_active' => true,
    ]);
});

function makeDraft($test): Content {
    $response = $test->actingAs($test->creative)->post(route('contents.store'), [
        'title' => 'Konten Uji',
        'caption' => 'Caption uji',
        'hashtags' => 'promo diskon',
        'social_account_ids' => [$test->account->id],
        'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'media' => [UploadedFile::fake()->image('post.jpg', 1080, 1080)],
    ]);
    $response->assertRedirect();
    return Content::latest('created_at')->firstOrFail();
}

it('lets a creative create a draft with media and a target account', function () {
    $content = makeDraft($this);

    expect($content->status)->toBe(ContentStatus::Draft)
        ->and($content->created_by)->toBe($this->creative->id)
        ->and($content->media)->toHaveCount(1)
        ->and($content->schedules)->toHaveCount(1)
        ->and($content->hashtags)->toBe('#promo #diskon');   // auto-prefixed
});

it('runs the full happy path: submit -> approve -> verify -> scheduled', function () {
    $content = makeDraft($this);

    $this->actingAs($this->creative)->post(route('contents.submit', $content))->assertRedirect();
    expect($content->fresh()->status)->toBe(ContentStatus::WaitingApproval);

    $this->actingAs($this->curator)->post(route('approvals.store', $content), [
        'action' => 'approved', 'note' => 'Bagus',
    ])->assertRedirect(route('approvals.index'));
    expect($content->fresh()->status)->toBe(ContentStatus::WaitingVerification)
        ->and($content->fresh()->curated_by)->toBe($this->curator->id);

    $this->actingAs($this->verifier)->post(route('verifications.store', $content), [
        'action' => 'approved',
        'checklist' => ['caption' => '1', 'hashtag' => '1', 'image' => '1', 'schedule' => '1'],
    ])->assertRedirect(route('verifications.index'));

    // Verified + has a date => goes straight into the publishing queue.
    $fresh = $content->fresh();
    expect($fresh->status)->toBe(ContentStatus::Scheduled)
        ->and($fresh->verified_by)->toBe($this->verifier->id)
        ->and($fresh->schedules()->first()->status)->toBe(ContentStatus::Scheduled);
});

it('sends content back on revision and allows resubmission', function () {
    $content = makeDraft($this);
    $this->actingAs($this->creative)->post(route('contents.submit', $content));

    $this->actingAs($this->curator)->post(route('approvals.store', $content), [
        'action' => 'revision', 'note' => 'Tolong perbaiki caption',
    ])->assertRedirect();

    $content->refresh();
    expect($content->status)->toBe(ContentStatus::Revision)
        ->and($content->revisions()->whereNull('resolved_at')->count())->toBe(1);

    // Creative may edit again while in revision, then resubmit.
    expect($this->creative->can('update', $content))->toBeTrue();

    $this->actingAs($this->creative)->post(route('contents.submit', $content))->assertRedirect();
    $content->refresh();
    expect($content->status)->toBe(ContentStatus::WaitingApproval)
        ->and($content->revisions()->whereNull('resolved_at')->count())->toBe(0);
});

it('requires a note when rejecting or asking for revision', function () {
    $content = makeDraft($this);
    $this->actingAs($this->creative)->post(route('contents.submit', $content));

    $this->actingAs($this->curator)
        ->post(route('approvals.store', $content), ['action' => 'rejected'])
        ->assertSessionHasErrors('note');
});

it('blocks out-of-order and unauthorised transitions', function () {
    $content = makeDraft($this);

    // Verifier cannot act on a draft.
    $this->actingAs($this->verifier)
        ->post(route('verifications.store', $content), ['action' => 'approved'])
        ->assertForbidden();

    // Director is read-only everywhere.
    $this->actingAs($this->director)->get(route('contents.create'))->assertForbidden();

    $this->actingAs($this->creative)->post(route('contents.submit', $content));

    // Creative cannot approve their own work.
    $this->actingAs($this->creative)
        ->post(route('approvals.store', $content), ['action' => 'approved'])
        ->assertForbidden();
});

it('writes an audit trail for every transition', function () {
    $content = makeDraft($this);
    $this->actingAs($this->creative)->post(route('contents.submit', $content));
    $this->actingAs($this->curator)->post(route('approvals.store', $content), ['action' => 'approved']);

    $actions = Activity::forSubject($content)->pluck('action');

    expect($actions)->toContain('content.created')
        ->and($actions)->toContain('content.submitted')
        ->and($actions)->toContain('content.approved');
});
