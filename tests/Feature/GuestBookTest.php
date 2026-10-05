<?php

/**
 * Buku Tamu / Antrian: public form → number → monitor → operator → ticket.
 */

use App\Enums\GuestBookService;
use App\Enums\GuestBookStatus;
use App\Enums\RoleName;
use App\Enums\TicketSource;
use App\Models\GuestBookEntry;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

/** A real (tiny) PNG, as the paraf canvas would send it. */
function parafDataUrl(): string
{
    $image = imagecreatetruecolor(40, 20);
    imageline($image, 2, 10, 38, 12, imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function guestForm(array $overrides = []): array
{
    return $overrides + [
        'whatsapp' => '0812 3456 7890',
        'phone' => '081234567891',
        'name' => 'Ahmad Fauzan Ramadhan',
        'nim' => '012345678',
        'gender' => 'laki_laki',
        'service' => GuestBookService::LegalisirIjazah->value,
        'description' => 'Legalisir 5 lembar.',
        'signature' => parafDataUrl(),
    ];
}

beforeEach(function () {
    Storage::fake('local');
});

it('gives a visitor the next number of the day without a login', function () {
    $before = (int) GuestBookEntry::today()->max('queue_number');

    $this->post(route('guest-book.store'), guestForm())
        ->assertRedirect(route('guest-book.done'));

    $entry = GuestBookEntry::latest('id')->first();

    expect($entry->queue_number)->toBe($before + 1)
        ->and($entry->status)->toBe(GuestBookStatus::Waiting)
        ->and($entry->whatsapp)->toStartWith('62')
        ->and($entry->signature_path)->not->toBeNull();

    Storage::disk('local')->assertExists($entry->signature_path);

    $this->get(route('guest-book.done'))
        ->assertOk()
        ->assertSee($entry->displayNumber())
        ->assertSee('Berhasil');

    $this->post(route('guest-book.store'), guestForm(['name' => 'Budi']));

    expect(GuestBookEntry::latest('id')->first()->queue_number)->toBe($before + 2);
});

it('ignores a queue number or status sent with the form', function () {
    $this->post(route('guest-book.store'), guestForm([
        'queue_number' => 1, 'status' => 'serving', 'ticket_id' => 1,
    ]))->assertRedirect(route('guest-book.done'));

    $entry = GuestBookEntry::latest('id')->first();

    expect($entry->status)->toBe(GuestBookStatus::Waiting)
        ->and($entry->ticket_id)->toBeNull();
});

it('requires the starred fields and a real paraf', function () {
    $this->post(route('guest-book.store'), [])
        ->assertSessionHasErrors(['whatsapp', 'phone', 'name', 'gender', 'service', 'signature']);

    // Labelled PNG, but not a PNG.
    $this->post(route('guest-book.store'), guestForm(['signature' => 'data:image/png;base64,'.base64_encode('<?php echo 1;')]))
        ->assertSessionHasErrors('signature');

    $this->post(route('guest-book.store'), guestForm(['whatsapp' => '12']))
        ->assertSessionHasErrors('whatsapp');
});

it('shows only the visitor\'s own number on the confirmation page', function () {
    // No entry in this session: nothing to show, and no id to guess.
    $this->get(route('guest-book.done'))->assertRedirect(route('guest-book.create'));
});

it('feeds the monitor a whitelist, never phone numbers or NIM', function () {
    $this->post(route('guest-book.store'), guestForm());

    $json = $this->getJson(route('guest-book.monitor.feed'))->assertOk()->json();
    $raw = json_encode($json);

    expect(collect($json['waiting'])->pluck('name'))->toContain('Ahmad F.R.')
        ->and($raw)->not->toContain('012345678')
        ->and($raw)->not->toContain('3456')
        ->and(array_keys($json['waiting'][0]))->toBe(['number', 'name', 'service', 'status', 'status_label']);
});

it('keeps the operator side behind a login and the permission', function () {
    $this->get(route('guest-book.admin.index'))->assertRedirect(route('login'));

    $this->actingAs(User::withRole(RoleName::Creative)->firstOrFail())
        ->get(route('guest-book.admin.index'))->assertForbidden();

    $this->actingAs(User::withRole(RoleName::Operator)->firstOrFail())
        ->get(route('guest-book.admin.index'))->assertOk();
});

it('moves an entry through the queue, and the monitor follows', function () {
    $this->post(route('guest-book.store'), guestForm());
    $entry = GuestBookEntry::latest('id')->first();
    $operator = User::withRole(RoleName::Operator)->firstOrFail();

    $this->actingAs($operator)->postJson(route('guest-book.admin.status', $entry), ['status' => 'called'])->assertOk();

    $feed = $this->getJson(route('guest-book.monitor.feed'))->json();
    expect(collect($feed['now'])->pluck('number'))->toContain($entry->displayNumber());

    // "Selesai" only through its form, never as a bare status change.
    $this->actingAs($operator)->postJson(route('guest-book.admin.status', $entry), ['status' => 'done'])
        ->assertUnprocessable();

    $this->actingAs($operator)->postJson(route('guest-book.admin.complete', $entry), [
        'service_process' => 'langsung', 'resolution' => 'langsung', 'completed_by' => $operator->id,
    ])->assertOk();

    $feed = $this->getJson(route('guest-book.monitor.feed'))->json();
    expect(collect($feed['now'])->merge($feed['waiting'])->pluck('number'))->not->toContain($entry->displayNumber());

    // "ticketed" is not something to set by hand.
    $this->actingAs($operator)->postJson(route('guest-book.admin.status', $entry), ['status' => 'ticketed'])
        ->assertUnprocessable();
});

it('turns an entry into exactly one ticket with source Buku Tamu', function () {
    $this->post(route('guest-book.store'), guestForm());
    $entry = GuestBookEntry::latest('id')->first();
    $operator = User::withRole(RoleName::Operator)->firstOrFail();

    $first = $this->actingAs($operator)->postJson(route('guest-book.admin.ticket', $entry))->assertOk()->json();
    $second = $this->actingAs($operator)->postJson(route('guest-book.admin.ticket', $entry))->assertOk()->json();

    $entry->refresh();
    $ticket = Ticket::where('number', $first['ticket'])->firstOrFail();

    expect($second['ticket'])->toBe($first['ticket'])
        ->and($second['created'])->toBeFalse()
        ->and(Ticket::where('source', TicketSource::GuestBook->value)->whereKey($ticket->id)->count())->toBe(1)
        ->and($entry->ticket_id)->toBe($ticket->id)
        ->and($entry->status)->toBe(GuestBookStatus::Ticketed)
        ->and($ticket->source)->toBe(TicketSource::GuestBook)
        ->and($ticket->requester_name)->toBe('Ahmad Fauzan Ramadhan')
        ->and($ticket->requester_nim)->toBe('012345678')
        ->and($ticket->extra['guest_book']['service'])->toBe('Legalisir Ijazah')
        ->and($ticket->extra['guest_book']['type'])->toBe('Permintaan Layanan');

    // Gone from the monitor, kept in the history.
    $feed = $this->getJson(route('guest-book.monitor.feed'))->json();
    expect(collect($feed['now'])->merge($feed['waiting'])->pluck('number'))->not->toContain($entry->displayNumber());

    // And it cannot be put back into the queue by a status change.
    $this->actingAs($operator)->postJson(route('guest-book.admin.status', $entry), ['status' => 'waiting'])
        ->assertUnprocessable();
});

it('hands the new ticket to an operator when one is chosen', function () {
    $this->post(route('guest-book.store'), guestForm());
    $entry = GuestBookEntry::latest('id')->first();
    $followUp = User::withRole(RoleName::FollowUp)->firstOrFail();

    $json = $this->actingAs(User::withRole(RoleName::Manager)->firstOrFail())
        ->postJson(route('guest-book.admin.ticket', $entry), ['assigned_to' => $followUp->id])
        ->assertOk()->json();

    expect(Ticket::where('number', $json['ticket'])->value('assigned_to'))->toBe($followUp->id);
});

it('never offers Buku Tamu as a source for a hand-made ticket', function () {
    $this->actingAs(User::withRole(RoleName::Operator)->firstOrFail())
        ->post(route('tickets.store'), [
            'subject' => 'Coba sumber buku tamu',
            'source' => TicketSource::GuestBook->value,
            'priority' => 'normal',
        ])
        ->assertSessionHasErrors('source');

    expect(TicketSource::manualOptions())->not->toHaveKey(TicketSource::GuestBook->value)
        ->and(array_keys(TicketSource::groupedOptions()))
        ->toBe(['Buku Tamu / Antrian', 'Komentar Sosial Media', 'Mahasiswa', 'Lainnya']);
});

it('links to the guest book from the login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('guest-book.create'))
        ->assertSee(route('guest-book.monitor'));
});

/* -----------------------------------------------------------------
 | Permintaan Layanan / Keluhan, Selesai form, search, export
 * ----------------------------------------------------------------- */

it('splits the form into Permintaan Layanan and Keluhan', function () {
    $this->get(route('guest-book.create'))
        ->assertOk()
        ->assertSee('Permintaan Layanan')
        ->assertSee('Keluhan')
        ->assertSee('Permasalahan Alih Kredit')
        ->assertSee('Tracking Bahan Ajar');

    $this->post(route('guest-book.store'), guestForm(['service' => GuestBookService::KeluhanNilai->value]))
        ->assertRedirect(route('guest-book.done'));

    $entry = GuestBookEntry::latest('id')->first();

    expect($entry->service->type())->toBe('keluhan')
        ->and($entry->serviceLabel())->toBe('Keluhan: Permasalahan Nilai');
});

it('requires a description when "Lainnya" is chosen', function () {
    $this->post(route('guest-book.store'), guestForm(['service' => GuestBookService::KeluhanLainnya->value, 'description' => '']))
        ->assertSessionHasErrors('description');

    $this->post(route('guest-book.store'), guestForm(['service' => GuestBookService::LayananLainnya->value, 'description' => 'Minta surat rekomendasi beasiswa']))
        ->assertRedirect(route('guest-book.done'));
});

it('records how a visit was finished and by which operator', function () {
    $this->post(route('guest-book.store'), guestForm());
    $entry = GuestBookEntry::latest('id')->first();
    $manager = User::withRole(RoleName::Manager)->firstOrFail();
    $followUp = User::withRole(RoleName::FollowUp)->firstOrFail();

    $json = $this->actingAs($manager)->postJson(route('guest-book.admin.complete', $entry), [
        'service_process' => 'langsung',
        'resolution' => 'langsung',
        'completed_by' => $followUp->id,
        'completion_note' => 'Berkas diserahkan.',
    ])->assertOk()->json();

    $entry->refresh();

    expect($entry->status)->toBe(GuestBookStatus::Done)
        ->and($entry->completed_by)->toBe($followUp->id)
        ->and($entry->handled_by)->toBe($manager->id)
        ->and($entry->service_process)->toBe('langsung')
        ->and($json['entry']['completed_by'])->toBe($followUp->name)
        ->and($json['entry']['process'])->toBe('Langsung');

    // Validated: an unknown option or a non-operator is refused.
    $this->post(route('guest-book.store'), guestForm(['name' => 'Kedua']));
    $second = GuestBookEntry::latest('id')->first();

    $this->actingAs($manager)->postJson(route('guest-book.admin.complete', $second), [
        'service_process' => 'kilat', 'resolution' => 'langsung',
        'completed_by' => User::withRole(RoleName::Creative)->firstOrFail()->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['service_process', 'completed_by']);
});

it('searches by name, phone or number within a date range', function () {
    $this->post(route('guest-book.store'), guestForm(['name' => 'Cari Saya Nanti', 'whatsapp' => '0811 2233 4455']));
    $entry = GuestBookEntry::latest('id')->first();
    $operator = User::withRole(RoleName::Operator)->firstOrFail();
    $today = now('Asia/Jakarta')->toDateString();

    foreach (['Cari Saya', '0811-2233-4455', $entry->displayNumber()] as $q) {
        $this->actingAs($operator)
            ->get(route('guest-book.admin.rows', ['q' => $q, 'from' => $today, 'to' => $today]))
            ->assertOk()
            ->assertSee('Cari Saya Nanti');
    }

    // A range that ends before today does not include it.
    $this->actingAs($operator)
        ->get(route('guest-book.admin.rows', ['q' => 'Cari Saya', 'from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertOk()
        ->assertDontSee('Cari Saya Nanti');
});

it('downloads the filtered list as xlsx', function () {
    $this->post(route('guest-book.store'), guestForm(['name' => 'Ekspor Tamu']));

    $response = $this->actingAs(User::withRole(RoleName::Operator)->firstOrFail())
        ->get(route('guest-book.admin.export', ['q' => 'Ekspor Tamu', 'filter' => 'all', 'format' => 'xlsx']));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('buku-tamu-')->toContain('.xlsx');
});
