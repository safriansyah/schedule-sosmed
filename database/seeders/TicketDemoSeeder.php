<?php

namespace Database\Seeders;

use App\Enums\FollowUpStatus;
use App\Enums\RoleName;
use App\Enums\StudentCondition;
use App\Enums\TicketFlag;
use App\Enums\TicketSource;
use App\Models\Interaction;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Illuminate\Database\Seeder;

/**
 * Three example tickets — one per way a ticket is born, at three different
 * stages of its life.
 *
 *   1. From an Instagram comment, still being worked, flagged Lead.
 *   2. From an imported student, in follow-up, with a NIM on it.
 *   3. Typed by hand and already closed, so the resolution is visible.
 *
 * Three identical tickets would teach nothing; these are chosen so the list,
 * the filters and the detail page all have something real to show.
 *
 * Marked with `extra.demo = true` rather than a visible prefix, so the screens
 * look like production while `--clear` can still find exactly these rows.
 */
class TicketDemoSeeder extends Seeder
{
    /** The fictional student the second example hangs on. */
    public const DEMO_NIM = 'DEMO00001';

    public function run(): void
    {
        $service = app(TicketService::class);
        $author = User::withRole(RoleName::Manager)->first() ?? User::withRole(RoleName::SuperAdmin)->first();
        $operator = User::withRole(RoleName::Operator)->orderBy('id')->first();

        if ($author === null) {
            $this->command?->warn('  Tidak ada pengguna untuk dijadikan pembuat tiket.');

            return;
        }

        $made = 0;
        $made += (int) $this->fromComment($service, $author, $operator);
        $made += (int) $this->fromStudent($service, $author, $operator);
        $made += (int) $this->closedByHand($service, $author, $operator);

        $this->command?->info("  {$made} contoh tiket dibuat.");
    }

    /* -----------------------------------------------------------------
     | 1. Born from a comment — the "Add to Ticket" path
     * ----------------------------------------------------------------- */

    private function fromComment(TicketService $service, User $author, ?User $operator): bool
    {
        // A demo comment that has not been escalated yet, so the inbox ↔
        // ticket loop is demonstrated rather than faked.
        $comment = Interaction::where('external_id', 'like', InteractionDemoSeeder::PREFIX.'%')
            ->whereDoesntHave('ticket')
            ->where('is_urgent', true)
            ->orderBy('occurred_at')
            ->first();

        if ($comment === null) {
            $this->command?->warn('  Lewati contoh dari komentar — jalankan interactions:demo dulu.');

            return false;
        }

        $ticket = $service->createFromInteraction($comment, $author, [
            'subject' => 'Keluhan layanan lambat dari Instagram',
            'category_id' => $this->category('Layanan & Informasi'),
            'sub_category_id' => $this->category('Layanan & Informasi', 'Keluhan layanan'),
        ]);

        $this->mark($ticket);

        if ($operator) {
            $service->assign($ticket, $operator, $author, 'Tolong dihubungi hari ini juga.');
        }

        $service->addFollowUp($ticket, [
            'action' => 'chat_wa',
            'channel_used' => 'wa',
            'response_text' => 'Sudah dihubungi lewat WhatsApp. Mahasiswa menjelaskan pembayaran sudah dilakukan '
                .'tetapi statusnya belum berubah. Tangkapan layar transfer sudah diminta.',
            'outcome' => 'netral',
            'status' => FollowUpStatus::OnProses->value,
            'additional_data' => ['no_hp' => '081277001122'],
            'next_action_at' => now()->addDay(),
        ], $operator ?? $author);

        $service->changeFlag($ticket->refresh(), TicketFlag::Lead, $author);

        return true;
    }

    /* -----------------------------------------------------------------
     | 2. Born from the imported student list
     * ----------------------------------------------------------------- */

    private function fromStudent(TicketService $service, User $author, ?User $operator): bool
    {
        $student = $this->demoStudent();

        $ticket = $service->createManual([
            'subject' => 'Billing pending semester lalu — perlu ditindaklanjuti',
            'description' => 'Mahasiswa terdata billing pending pada semester lalu. '
                .'Perlu dipastikan apakah akan melanjutkan atau mengajukan cuti.',
            'source' => TicketSource::StudentImport->value,
            'student_id' => $student->id,
            'category_id' => $this->category('Pembayaran'),
            'sub_category_id' => $this->category('Pembayaran', 'Billing NAC belum bayar'),
            'priority' => 'high',
            'assigned_to' => $operator?->id,
            'due_at' => now()->addDays(3),
        ], $author);

        $this->mark($ticket);

        // The NIM the case turned out to be about — links back to the import.
        $service->saveDetail($ticket, ['nim' => $student->nim], $author);

        $service->addFollowUp($ticket, [
            'action' => 'tidak_respon',
            'channel_used' => 'telepon',
            'response_text' => 'Telepon pertama tidak diangkat.',
            'outcome' => 'belum_jelas',
            'status' => FollowUpStatus::OnProses->value,
        ], $operator ?? $author);

        $service->addFollowUp($ticket->refresh(), [
            'action' => 'chat_wa',
            'channel_used' => 'wa',
            'response_text' => 'Dibalas lewat WhatsApp. Mahasiswa menyatakan akan membayar pekan depan '
                .'setelah gajian, dan minta diingatkan kembali.',
            'outcome' => 'positif',
            'status' => FollowUpStatus::OnProses->value,
            'additional_data' => ['Keterangan' => 'Minta diingatkan Senin depan'],
            'next_action_at' => now()->addDays(5),
        ], $operator ?? $author);

        return true;
    }

    /**
     * A made-up student for the example to hang on.
     *
     * Deliberately NOT one of the imported people. The follow-ups below are
     * invented, and invented calls filed against a real person's record are
     * indistinguishable from real ones the moment somebody opens that record —
     * they would ring a stranger back about a payment promise never made. The
     * name and NIM here announce themselves as examples, and `--clear` takes
     * this row away with the tickets.
     */
    private function demoStudent(): Student
    {
        $student = Student::withTrashed()->firstOrNew(['nim' => self::DEMO_NIM]);

        $student->fill([
            'nama' => 'CONTOH MAHASISWA (DATA DEMO)',
            'no_hp_raw' => '081200000001',
            'email' => 'contoh.mahasiswa@demo.example',
            'program_studi' => 'Manajemen',
            'fakultas' => 'Fakultas Ekonomi dan Bisnis',
            'kabupaten' => 'Kota Pangkalpinang',
            'pokjar' => 'Pangkalpinang',
            'kategori_masalah' => StudentCondition::OngoingBillingPending->value,
            'kategori_masalah_raw' => 'Billing NAC Belum Bayar',
            'sumber_data' => 'contoh',
            'extra' => ['demo' => true],
        ]);

        $student->deleted_at = null;
        $student->save();

        return $student->refresh();
    }

    /* -----------------------------------------------------------------
     | 3. Typed by hand, and already finished
     * ----------------------------------------------------------------- */

    private function closedByHand(TicketService $service, User $author, ?User $operator): bool
    {
        $ticket = $service->createManual([
            'subject' => 'Mahasiswa menanyakan jadwal tutorial online',
            'description' => 'Menghubungi kantor lewat telepon, menanyakan jadwal tutorial online semester ini.',
            'source' => TicketSource::Manual->value,
            'category_id' => $this->category('Akademik'),
            'sub_category_id' => $this->category('Akademik', 'Jadwal tutorial'),
            'priority' => 'low',
            'requester_name' => 'Sri Wahyuni',
            'requester_phone' => '081255009911',
            'assigned_to' => $operator?->id,
        ], $author);

        $this->mark($ticket);

        $service->addFollowUp($ticket, [
            'action' => 'dibalas',
            'channel_used' => 'telepon',
            'response_text' => 'Jadwal tutorial sudah dikirim lewat WhatsApp dan dijelaskan cara mengaksesnya di LMS.',
            'outcome' => 'positif',
            'status' => FollowUpStatus::OnProses->value,
        ], $operator ?? $author);

        $service->close(
            $ticket->refresh(),
            'Jadwal tutorial sudah dikirim dan mahasiswa mengonfirmasi sudah bisa mengakses LMS.',
            $operator ?? $author,
        );

        return true;
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /** Invisible on screen, findable by --clear. */
    private function mark(Ticket $ticket): void
    {
        $ticket->forceFill(['extra' => array_merge($ticket->extra ?? [], ['demo' => true])])->save();
    }

    private function category(string $parent, ?string $child = null): ?int
    {
        $root = TicketCategory::roots()->where('name', $parent)->first();

        if ($root === null) {
            return null;
        }

        return $child === null
            ? $root->id
            : $root->children()->where('name', $child)->value('id');
    }
}
