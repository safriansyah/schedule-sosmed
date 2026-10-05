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

    $this->actingAs($operator)->postJson(route('guest-book.admin.status', $entry), ['status' => 'done'])->assertOk();

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
        ->and($ticket->extra['guest_book']['service'])->toBe('LEGALISIR IJAZAH');

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
