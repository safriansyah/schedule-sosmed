<?php

/**
 * The visitor rates their own finished visit: 1–5 stars and a review, through
 * a signed link, once.
 */

use App\Enums\GuestBookService;
use App\Enums\GuestBookStatus;
use App\Enums\RoleName;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\GuestBookEntry;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;

uses(DatabaseTransactions::class);

function ratingEntry(GuestBookStatus $status = GuestBookStatus::Done, array $overrides = []): GuestBookEntry
{
    return GuestBookEntry::forceCreate($overrides + [
        'queue_date' => now('Asia/Jakarta')->toDateString(),
        'queue_number' => 800 + random_int(0, 199),
        'whatsapp' => '6281299991001', 'phone' => '6281299991001',
        'name' => 'Tamu Penilai', 'gender' => 'perempuan',
        'service' => GuestBookService::cases()[0]->value,
        'status' => $status->value,
        'finished_at' => $status === GuestBookStatus::Done ? now() : null,
    ]);
}

function rateUrl(GuestBookEntry $entry, string $route = 'guest-book.rate'): string
{
    return URL::signedRoute($route, $entry, absolute: false);
}

it('lets the visitor rate a finished visit once, with stars and a review', function () {
    $entry = ratingEntry();

    $this->get(rateUrl($entry))->assertOk()->assertSee('Bagaimana layanan yang Anda terima?');

    $this->post(rateUrl($entry, 'guest-book.rate.store'), ['rating' => 5, 'review' => 'Petugas ramah, cepat.'])
        ->assertRedirect();

    expect($entry->fresh())
        ->rating->toBe(5)
        ->review->toBe('Petugas ramah, cepat.')
        ->rated_at->not->toBeNull();

    // Once only: a second answer does not replace the first.
    $this->post(rateUrl($entry, 'guest-book.rate.store'), ['rating' => 1, 'review' => 'Ganti']);
    expect($entry->fresh()->rating)->toBe(5);

    $this->get(rateUrl($entry))->assertOk()->assertSee('Penilaian Anda')->assertSee('Petugas ramah, cepat.')
        ->assertDontSee('Kirim Penilaian');
});

it('needs a valid signature and a star count', function () {
    $entry = ratingEntry();

    // The id alone opens nothing.
    $this->get(route('guest-book.rate', $entry))->assertForbidden();
    $this->post(route('guest-book.rate.store', $entry), ['rating' => 5])->assertForbidden();

    $this->post(rateUrl($entry, 'guest-book.rate.store'), ['rating' => 9])->assertSessionHasErrors('rating');
    $this->post(rateUrl($entry, 'guest-book.rate.store'), [])->assertSessionHasErrors('rating');

    expect($entry->fresh()->rating)->toBeNull();
});

it('waits until the service is over — at the desk, or through its ticket', function () {
    $waiting = ratingEntry(GuestBookStatus::Waiting);

    $this->get(rateUrl($waiting))->assertOk()->assertSee('Layanan Anda belum selesai');
    $this->post(rateUrl($waiting, 'guest-book.rate.store'), ['rating' => 4])->assertSessionHasErrors('rating');
    expect($waiting->fresh()->rating)->toBeNull();

    // A visit that became a ticket: rateable once the ticket is resolved.
    $ticket = Ticket::create([
        'number' => 'TKT-RATE-'.substr(uniqid(), -6), 'subject' => 'Dari buku tamu', 'source' => TicketSource::GuestBook,
        'status' => TicketStatus::Assigned, 'created_by' => User::withRole(RoleName::Manager)->firstOrFail()->id,
    ]);
    $ticketed = ratingEntry(GuestBookStatus::Ticketed, ['ticket_id' => $ticket->id]);

    $this->get(rateUrl($ticketed))->assertOk()->assertSee('Layanan Anda belum selesai');

    $ticket->forceFill(['status' => TicketStatus::Closed->value])->save();

    $this->post(rateUrl($ticketed, 'guest-book.rate.store'), ['rating' => 4])->assertRedirect();
    expect($ticketed->fresh()->rating)->toBe(4);
});

it('offers the rating on the visitor\'s own confirmation page once served', function () {
    $entry = ratingEntry();

    $this->withSession(['guest_book.entry_id' => $entry->id])
        ->get(route('guest-book.done'))
        ->assertOk()
        ->assertSee('Beri Penilaian');
});

it('shows the desk the rating, the average, and a WhatsApp link to ask for one', function () {
    $rated = ratingEntry(overrides: ['name' => 'Tamu Sudah Menilai', 'rating' => 4, 'review' => 'Bagus sekali', 'rated_at' => now()]);
    $unrated = ratingEntry(overrides: ['name' => 'Tamu Belum Menilai']);
    $frontdesk = User::withRole(RoleName::Frontdesk)->firstOrFail();
    $today = now('Asia/Jakarta')->toDateString();

    $this->actingAs($frontdesk)
        ->get(route('guest-book.admin.rows', ['from' => $today, 'to' => $today, 'filter' => 'all', 'q' => 'Tamu']))
        ->assertOk()
        ->assertSee('Rating rata-rata')
        ->assertSee('Bagus sekali')
        ->assertSee('Minta Penilaian');

    // The WhatsApp link carries a signed rating URL on the public domain.
    expect($unrated->ratingWhatsAppUrl())->toStartWith('https://wa.me/6281299991001?text=')
        ->and(urldecode($unrated->ratingWhatsAppUrl()))->toContain(rtrim(config('app.url'), '/').'/guest-book/nilai/'.$unrated->id.'?signature=')
        ->and($rated->canBeRated())->toBeFalse();
});

it('filters Unsigned & Ticket by kategori masalah, with counts', function () {
    \App\Models\Student::create([
        'nim' => 'KATMAS0001', 'nama' => 'Mahasiswa Kategori', 'kabupaten' => 'Kabupaten Kategori',
        'kategori_masalah' => \App\Enums\StudentCondition::AdmisiGagalValidasi->value,
        'assignment_status' => \App\Enums\AssignmentStatus::Unassigned->value,
    ]);
    $manager = User::withRole(RoleName::Manager)->firstOrFail();

    $this->actingAs($manager)->get(route('students.unsigned', ['kabupaten' => 'Kabupaten Kategori']))
        ->assertOk()
        ->assertSee('Kategori Masalah')
        ->assertSee('Admisi Gagal Validasi Semester Lalu (1)');

    $this->actingAs($manager)->get(route('students.unsigned', ['kabupaten' => 'Kabupaten Kategori', 'kondisi' => 'non_aktif_dn']))
        ->assertOk()->assertDontSee('KATMAS0001');
    $this->actingAs($manager)->get(route('students.unsigned', ['kabupaten' => 'Kabupaten Kategori', 'kondisi' => 'admisi_gagal_validasi']))
        ->assertOk()->assertSee('KATMAS0001');
});
