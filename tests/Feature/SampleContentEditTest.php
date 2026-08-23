<?php
use App\Enums\ContentStatus;
use App\Enums\RoleName;
use App\Models\{Content, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('shows the sample content and lets its creator edit it', function () {
    $content = Content::where('title', 'like', 'Konten Contoh%')->firstOrFail();
    $creative = User::findOrFail($content->created_by);

    // The shared dev DB may have moved the sample along the workflow through
    // manual use; force its editable (Draft) precondition. Rolls back after.
    $content->update(['status' => ContentStatus::Draft]);

    // Visible in the list and openable
    $this->actingAs($creative)->get(route('contents.index'))
        ->assertOk()->assertSee('Konten Contoh');

    $this->actingAs($creative)->get(route('contents.show', $content))
        ->assertOk()->assertSee('#promo')->assertSee('Pangkalpinang');

    // The edit form loads with the media rules visible
    $this->actingAs($creative)->get(route('contents.edit', $content))
        ->assertOk()
        ->assertSee('Ketentuan media')
        ->assertSee('Rasio gambar');

    // And an actual edit persists
    $this->actingAs($creative)->put(route('contents.update', $content), [
        'title' => 'Konten Contoh — Judul Diedit',
        'caption' => 'Caption sudah diperbarui.',
        'hashtags' => 'promo baru',
        'social_account_ids' => $content->schedules->pluck('social_account_id')->all(),
        'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
    ])->assertRedirect(route('contents.show', $content));

    $fresh = $content->fresh();
    expect($fresh->title)->toBe('Konten Contoh — Judul Diedit')
        ->and($fresh->hashtags)->toBe('#promo #baru')
        ->and($fresh->media)->toHaveCount(1);   // media kept
});

it('renders the dashboard with period filters for admin and director', function () {
    foreach ([RoleName::SuperAdmin, RoleName::Director] as $role) {
        $user = User::withRole($role)->firstOrFail();

        foreach (array_keys(App\Services\DashboardService::PERIODS) as $period) {
            $this->actingAs($user)->get(route('dashboard', ['period' => $period]))
                ->assertOk()
                ->assertSee('Produktivitas Tim')
                ->assertSee('Performa Akun');
        }
    }
});
