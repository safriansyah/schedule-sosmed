<?php

/**
 * Data Lead: pick a source → upload → check the columns → import → list.
 */

use App\Enums\LeadSource;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\User;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('local');
    $this->manager = User::withRole(RoleName::Manager)->firstOrFail();
});

function leadCsv(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('leads.csv', $body);
}

/** Upload, then confirm; returns the import after it ran. */
function importLeads($test, UploadedFile $file, LeadSource $source): LeadImport
{
    $test->actingAs($test->manager)
        ->post(route('leads.import.store'), ['source' => $source->value, 'file' => $file])
        ->assertRedirect();

    $import = LeadImport::latest('id')->firstOrFail();

    $test->actingAs($test->manager)
        ->post(route('leads.import.confirm', $import))
        ->assertRedirect(route('leads.import.show', $import));

    return $import->fresh();
}

it('imports leads with the source picked before the upload', function () {
    $csv = "Nama,No HP,Kota / Kabupaten,Asal Sekolah,Catatan\n"
        ."Lead Uji Satu,0812-9000-0001,pangkalpinang,SMA Negeri 1 Pangkalpinang,minat manajemen\n"
        ."Lead Uji Dua,81290000002,Bangka Tengah,,\n"           // no school: optional
        ."Lead Uji Tiga,12345,Bangka,,\n"                       // not a phone number
        .",081290000004,Bangka,,\n"                              // no name
        ."Lead Uji Satu Lagi,+62 812 9000 0001,Bangka,,\n"       // same number as line 2
        .",,,,\n";                                               // blank line: ignored

    $file = leadCsv($csv);

    $this->actingAs($this->manager)
        ->post(route('leads.import.store'), ['source' => LeadSource::MetaAds->value, 'file' => $file])
        ->assertRedirect();

    $import = LeadImport::latest('id')->firstOrFail();

    // The preview reads the file but writes nothing.
    $this->actingAs($this->manager)->get(route('leads.import.preview', $import))
        ->assertOk()
        ->assertSee('Iklan Meta Ads')
        ->assertSee('Lead Uji Satu')
        ->assertSee('Catatan');
    expect(Lead::where('nama', 'like', 'Lead Uji%')->count())->toBe(0);

    $this->actingAs($this->manager)->post(route('leads.import.confirm', $import))->assertRedirect();
    $import->refresh();

    expect($import->status)->toBe(LeadImport::STATUS_COMPLETED)
        ->and($import->total_rows)->toBe(5)
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(2)
        ->and($import->duplicate_count)->toBe(1);

    $one = Lead::where('no_hp', '6281290000001')->firstOrFail();
    expect($one->nama)->toBe('Lead Uji Satu')
        ->and($one->kota)->toBe('Pangkalpinang')
        ->and($one->asal_sekolah)->toBe('SMA Negeri 1 Pangkalpinang')
        ->and($one->sumber)->toBe(LeadSource::MetaAds)
        ->and($one->extra)->toBe(['Catatan' => 'minat manajemen']);

    expect(Lead::where('no_hp', '6281290000002')->value('asal_sekolah'))->toBeNull();

    // The rejected rows are listed and downloadable.
    $this->actingAs($this->manager)->get(route('leads.import.show', $import))
        ->assertOk()
        ->assertSee('No HP tidak valid')
        ->assertSee('Nama kosong')
        ->assertSee('sama dengan baris 2');
    $this->actingAs($this->manager)->get(route('leads.import.errors', $import))->assertOk();

    // Confirming again does not import twice.
    $this->actingAs($this->manager)->post(route('leads.import.confirm', $import))->assertRedirect();
    expect(Lead::where('nama', 'like', 'Lead Uji%')->count())->toBe(2);
});

it('does not duplicate a number already on file, and keeps its first source', function () {
    importLeads($this, leadCsv("Nama,No HP,Kota\nLead Uji Ulang,081290000011,Bangka\n"), LeadSource::MetaAds);

    $second = importLeads($this, leadCsv("nama lengkap,no wa,kabupaten,sekolah\nLead Uji Ulang Benar,081290000011,,SMK Negeri 2\n"), LeadSource::Mitra);

    expect($second->imported_count)->toBe(0)->and($second->updated_count)->toBe(1);

    $lead = Lead::where('no_hp', '6281290000011')->sole();
    expect($lead->nama)->toBe('Lead Uji Ulang Benar')
        ->and($lead->kota)->toBe('Bangka')              // a blank cell does not erase it
        ->and($lead->asal_sekolah)->toBe('SMK Negeri 2')  // the gap is filled
        ->and($lead->sumber)->toBe(LeadSource::MetaAds);
});

it('reads an xlsx with Meta Ads style headings', function () {
    $path = sys_get_temp_dir().'/lead-meta-'.uniqid().'.xlsx';
    (new XlsxWriter)->write($path, ['full_name', 'phone_number', 'city', 'ad_name'], [
        ['Lead Uji Meta', '6281290000021', 'Belitung', 'Kampanye Oktober'],
    ]);

    $import = importLeads($this, new UploadedFile($path, 'meta-leads.xlsx', null, null, true), LeadSource::MetaAds);
    @unlink($path);

    expect($import->imported_count)->toBe(1);
    expect(Lead::where('no_hp', '6281290000021')->sole())
        ->kota->toBe('Belitung')
        ->extra->toBe(['ad_name' => 'Kampanye Oktober']);
});

it('stops a file that lacks a required column, and says which', function () {
    $file = leadCsv("Nama,Kota\nLead Tanpa HP,Bangka\n");

    $this->actingAs($this->manager)
        ->post(route('leads.import.store'), ['source' => LeadSource::TiktokAds->value, 'file' => $file]);
    $import = LeadImport::latest('id')->firstOrFail();

    $this->actingAs($this->manager)->get(route('leads.import.preview', $import))
        ->assertOk()->assertSee('Kolom wajib tidak ditemukan')->assertSee('No HP');

    $this->actingAs($this->manager)->post(route('leads.import.confirm', $import));

    expect($import->fresh())
        ->status->toBe(LeadImport::STATUS_FAILED)
        ->error_message->toContain('No HP');
});

it('requires the source to be picked first', function () {
    $before = LeadImport::count();

    $this->actingAs($this->manager)
        ->post(route('leads.import.store'), ['file' => leadCsv("Nama,No HP,Kota\nA,081290000031,B\n")])
        ->assertSessionHasErrors('source');

    expect(LeadImport::count())->toBe($before);
});

it('lists leads newest first, filtered by source and search', function () {
    importLeads($this, leadCsv("Nama,No HP,Kota\nLead Uji Sosprom,081290000041,Bangka Barat\n"), LeadSource::AbsensiSosprom);
    importLeads($this, leadCsv("Nama,No HP,Kota\nLead Uji Pusat,081290000042,Belitung Timur\n"), LeadSource::UtPusat);

    $this->actingAs($this->manager)->get(route('leads.index'))
        ->assertOk()
        ->assertSeeInOrder(['Lead Uji Pusat', 'Lead Uji Sosprom']);

    $this->actingAs($this->manager)->get(route('leads.index', ['sumber' => 'absensi_sosprom']))
        ->assertOk()->assertSee('Lead Uji Sosprom')->assertDontSee('Lead Uji Pusat');

    $this->actingAs($this->manager)->get(route('leads.index', ['q' => '0812-9000-0042']))
        ->assertOk()->assertSee('Lead Uji Pusat')->assertDontSee('Lead Uji Sosprom');
});

it('lets the Manager import, the Director only look, and keeps others out', function () {
    $this->actingAs($this->manager)->get(route('leads.index'))->assertOk();
    $this->actingAs($this->manager)->get(route('leads.import.index'))->assertOk()->assertSee('Data Lead dari UT Pusat');

    $director = User::withRole(RoleName::Director)->firstOrFail();
    $this->actingAs($director)->get(route('leads.index'))->assertOk();
    $this->actingAs($director)->get(route('leads.import.index'))->assertForbidden();

    foreach ([RoleName::Operator, RoleName::FollowUp, RoleName::Frontdesk, RoleName::Creative] as $role) {
        $user = User::withRole($role)->firstOrFail();
        $this->actingAs($user)->get(route('leads.index'))->assertForbidden();
        $this->actingAs($user)->get(route('leads.import.index'))->assertForbidden();
    }
});
