<?php
use App\Enums\ContentStatus;
use App\Enums\MediaType;
use App\Enums\RoleName;
use App\Models\{Content, MediaFile, SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('shows the sample content and lets its creator edit it', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    // Built here rather than looked up.
    //
    // This used to fetch a row titled "Konten Contoh%" that no seeder creates
    // -- it was hand-made in the shared dev database, so the test passed on
    // that one machine and failed everywhere else, including after a
    // data:reset. What it is really about is the edit flow, so it now makes
    // its own subject and rolls it back with the transaction.
    $content = Content::create([
        'title' => 'Konten Contoh untuk Uji Edit',
        'caption' => 'Halo warga Pangkalpinang, ada promo baru minggu ini.',
        'hashtags' => '#promo #ut',
        'status' => ContentStatus::Draft,
        'created_by' => $creative->id,
    ]);

    // One attachment, so "media survives an edit" is actually exercised.
    MediaFile::create([
        'content_id' => $content->id,
        'path' => 'media/uji-edit.jpg',
        'type' => MediaType::Image,
        'position' => 1,
    ]);

    $content->refresh();

    // Visible in the list and openable
    $this->actingAs($creative)->get(route('contents.index'))
        ->assertOk()->assertSee('Konten Contoh untuk Uji Edit');

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
        // Required, minimum one: the update form always posts a destination,
        // and this content was created without a schedule row.
        'social_account_ids' => [SocialAccount::active()->value('id')],
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
