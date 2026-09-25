<?php

use App\Enums\RegionLevel;
use App\Enums\RoleName;
use App\Models\{Contact, Region, User};
use App\Services\Crm\ContactResolver;
use App\Enums\SocialPlatform;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/**
 * The wilayah dropdowns on a contact are loaded level by level from
 * /contacts/regions. The first call asks for the TOP level, which it does by
 * sending no parent at all.
 *
 * That is where this broke: the controller read the parameter with
 * $request->integer('parent'), which answers 0 for a missing value, and then
 * queried parent_id = 0. Provinces carry parent_id NULL, so the list came back
 * empty — every dropdown rendered blank and disabled, even for a contact whose
 * region was already saved. The data was fine; only the picker was blind.
 */
function operatorUser(): User
{
    return User::withRole(RoleName::Operator)->firstOrFail();
}

it('returns the provinces when no parent is given', function () {
    $expected = Region::level(RegionLevel::Province)->count();

    // Both shapes the browser actually sends: absent, and present-but-empty.
    foreach (['/contacts/regions', '/contacts/regions?parent='] as $url) {
        $response = $this->actingAs(operatorUser())->getJson($url);

        $response->assertOk();
        expect($response->json())->toHaveCount($expected);
    }
});

it('never answers with an empty province list', function () {
    // The symptom the user saw, pinned directly.
    $provinces = $this->actingAs(operatorUser())->getJson('/contacts/regions')->json();

    expect($provinces)->not->toBeEmpty();
    expect($provinces[0])->toHaveKeys(['id', 'name', 'level']);
    expect($provinces[0]['level'])->toBe(RegionLevel::Province->value);
});

it('walks down one level at a time', function () {
    $province = Region::level(RegionLevel::Province)
        ->whereHas('children')
        ->first();

    if (! $province) {
        $this->markTestSkipped('Butuh data wilayah di bawah tingkat provinsi.');
    }

    $regencies = $this->actingAs(operatorUser())
        ->getJson("/contacts/regions?parent={$province->id}")
        ->assertOk()
        ->json();

    expect($regencies)->not->toBeEmpty()
        ->and($regencies[0]['level'])->toBe(RegionLevel::Regency->value);

    // …and the next level down resolves from that one.
    $districts = $this->actingAs(operatorUser())
        ->getJson("/contacts/regions?parent={$regencies[0]['id']}")
        ->assertOk()
        ->json();

    foreach ($districts as $district) {
        expect($district['level'])->toBe(RegionLevel::District->value);
    }
});

it('returns an empty list for a leaf, not the provinces again', function () {
    $village = Region::level(RegionLevel::Village)->first();

    if (! $village) {
        $this->markTestSkipped('Butuh data desa.');
    }

    // A blank parent means "top level"; a real parent with no children means
    // "nothing below". Those must not collapse into the same answer.
    $children = $this->actingAs(operatorUser())
        ->getJson("/contacts/regions?parent={$village->id}")
        ->assertOk()
        ->json();

    expect($children)->toBeEmpty();
});

it('keeps the endpoint behind the contacts permission', function () {
    // A creative has no business reading the contact database.
    $this->actingAs(User::withRole(RoleName::Creative)->firstOrFail())
        ->getJson('/contacts/regions')
        ->assertForbidden();
});

it('saves the deepest level chosen and shows the full path', function () {
    $village = Region::level(RegionLevel::Village)->first();

    if (! $village) {
        $this->markTestSkipped('Butuh data desa.');
    }

    $contact = app(ContactResolver::class)
        ->resolve(SocialPlatform::Instagram, 'ujiwilayah'.uniqid());

    $this->actingAs(operatorUser())
        ->put(route('contacts.update', $contact), [
            'full_name' => 'Uji Wilayah',
            'region_id' => $village->id,
        ])
        ->assertRedirect();

    $contact->refresh();

    expect($contact->region_id)->toBe($village->id)
        // Alamat lengkap dirangkai dari rantai induknya, bukan hanya nama desa.
        ->and($contact->region->label())->toContain($village->name);
});

it('renders the picker with the saved chain so it can rehydrate', function () {
    $village = Region::level(RegionLevel::Village)->first();

    if (! $village) {
        $this->markTestSkipped('Butuh data desa.');
    }

    $contact = app(ContactResolver::class)
        ->resolve(SocialPlatform::Instagram, 'ujihidrasi'.uniqid());
    $contact->forceFill(['region_id' => $village->id])->save();

    $html = $this->actingAs(operatorUser())
        ->get(route('contacts.show', $contact))
        ->assertOk()
        ->getContent();

    // Tanpa rantai ini, dropdown tampil kosong meski wilayahnya sudah tersimpan.
    expect($html)->toContain('regionPicker')
        ->and($html)->toContain('provinsi')
        ->and($html)->toContain((string) $village->id);
});
