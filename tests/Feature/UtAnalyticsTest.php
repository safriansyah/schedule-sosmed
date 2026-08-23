<?php
use App\Enums\RoleName;
use App\Models\{Dataset, DatasetItem, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function makeDataset(): Dataset {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    $dataset = Dataset::create([
        'name' => 'Dataset Uji', 'slug' => 'dataset-uji-'.uniqid(),
        'status' => Dataset::STATUS_COMPLETED, 'progress' => 100,
        'total_rows' => 3, 'valid_count' => 2, 'invalid_count' => 1, 'qualified_count' => 1,
        'created_by' => $creative->id, 'imported_at' => now(),
    ]);

    foreach ([
        ['a', 'instagram', 12000, true, true],
        ['b', 'instagram', 800, true, false],
        ['c', 'tiktok', 0, false, false],
    ] as [$u, $p, $f, $valid, $qualified]) {
        DatasetItem::create([
            'dataset_id' => $dataset->id, 'name' => "Akun {$u}", 'username' => $u,
            'platform' => $p, 'followers' => $f, 'following' => 10, 'posts' => 5,
            'is_valid' => $valid, 'is_qualified' => $qualified,
        ]);
    }

    return $dataset;
}

it('shows the dataset list only to admin and director', function () {
    makeDataset();

    // UT Monitoring Account is limited to these two roles.
    foreach ([RoleName::SuperAdmin, RoleName::Director] as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->get(route('datasets.index'))->assertOk();
    }

    // The rest are forbidden.
    foreach ([RoleName::Creative, RoleName::Curator, RoleName::Verifier] as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->get(route('datasets.index'))->assertForbidden();
    }
});

it('renders analytics for a completed dataset', function () {
    $dataset = makeDataset();

    $this->actingAs(User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->get(route('datasets.show', $dataset))
        ->assertOk()
        ->assertSee('Dataset Uji');
});

it('serves the server-side table feed', function () {
    $dataset = makeDataset();

    $response = $this->actingAs(User::withRole(RoleName::Director)->firstOrFail())
        ->getJson(route('datasets.table', $dataset));

    $response->assertOk()->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
    expect($response->json('meta.total'))->toBe(3);
});

it('filters the table feed by platform', function () {
    $dataset = makeDataset();

    $response = $this->actingAs(User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->getJson(route('datasets.table', [$dataset, 'platform' => 'tiktok']));

    expect($response->json('meta.total'))->toBe(1);
});

it('imports an uploaded JSON file end to end', function () {
    Storage::fake('local');
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $json = json_encode(['data' => [
        ['nama' => 'Budi', 'username' => 'budi', 'platform' => 'instagram', 'followers' => 5000, 'valid' => true, 'qualified' => true],
        ['nama' => 'Siti', 'username' => 'siti', 'platform' => 'tiktok', 'followers' => 120, 'valid' => false],
    ]]);

    $this->actingAs($admin)->post(route('datasets.store'), [
        'name' => 'Impor Uji',
        'file' => UploadedFile::fake()->createWithContent('akun.json', $json),
    ])->assertRedirect();

    $dataset = Dataset::where('name', 'Impor Uji')->firstOrFail();

    expect($dataset->total_rows)->toBe(2)
        ->and($dataset->valid_count)->toBe(1)
        ->and($dataset->items()->where('username', 'budi')->value('followers'))->toBe(5000);
});

it('blocks uploads from roles without the manage permission', function () {
    Storage::fake('local');

    $this->actingAs(User::withRole(RoleName::Director)->firstOrFail())
        ->post(route('datasets.store'), [
            'name' => 'Tolak',
            'file' => UploadedFile::fake()->createWithContent('x.json', '[]'),
        ])->assertForbidden();
});
