<?php

namespace Database\Seeders;

use App\Enums\Intent;
use App\Enums\InteractionStatus;
use App\Enums\InteractionType;
use App\Enums\RoleName;
use App\Enums\Sentiment;
use App\Enums\SocialPlatform;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Example comments for the inbox, spread across every tab.
 *
 * The real synced comments arrive unassigned, so "Tugas Saya" is empty for
 * everyone until a manager hands work out — which makes the tab impossible to
 * demonstrate or learn from. These rows fill it.
 *
 * They are clearly marked: every handle starts with `demo_` and the external
 * id with `DEMO-`, so they are trivial to spot and to remove:
 *
 *     php artisan db:seed --class=InteractionDemoSeeder   # add
 *     php artisan interactions:demo --clear               # remove
 *
 * Nothing here touches the real comments; they are separate rows.
 */
class InteractionDemoSeeder extends Seeder
{
    public const PREFIX = 'DEMO-';

    /**
     * text, type, sentiment, intent, urgent, needs reply, status, hours ago,
     * and which role should own it.
     */
    private const SAMPLES = [
        // --- Assigned, still open: these are what "Tugas Saya" shows. -------
        ['Min, saya sudah bayar registrasi tapi status di SIA masih belum aktif. Tolong dicek ya.',
            'comment', 'negative', 'complaint', false, true, 'in_progress', 5, RoleName::Operator, 0],
        ['Halo min, mau tanya untuk pembayaran semester ini batas akhirnya kapan?',
            'dm', 'neutral', 'question', false, true, 'new', 9, RoleName::Operator, 0],
        ['Saya mau daftar tapi berkas ijazah saya hilang, apakah bisa pakai surat keterangan?',
            'comment', 'neutral', 'question', false, true, 'in_progress', 20, RoleName::Operator, 0],
        ['Kenapa layanan UT lambat sekali? Sudah seminggu tidak ada kabar.',
            'comment', 'negative', 'complaint', true, true, 'in_progress', 2, RoleName::Operator, 1],
        ['Min tolong dibalas, saya sudah DM tiga kali tidak direspon.',
            'dm', 'negative', 'complaint', true, true, 'new', 3, RoleName::Pic, 0],
        ['Apakah UT Pangkalpinang buka pendaftaran untuk semester depan?',
            'comment', 'neutral', 'question', false, true, 'new', 14, RoleName::Pic, 1],

        // --- Unassigned: these populate the other queues. -------------------
        ['Kampus abal-abal, jangan daftar di sini!',
            'comment', 'negative', 'disparagement', true, true, 'new', 1, null, 0],
        ['Biaya kuliahnya berapa ya min per semester?',
            'comment', 'neutral', 'question', false, true, 'new', 6, null, 0],
        ['Terima kasih min, pelayanannya cepat sekali kemarin.',
            'comment', 'positive', 'praise', false, false, 'new', 26, null, 0],
        ['Promo pinjaman cepat cair hubungi WA 08xx', // spam, so it can be ignored
            'comment', 'neutral', 'spam', false, false, 'new', 30, null, 0],

        // --- Already handled: fills the "Selesai" tab. ----------------------
        ['Min, link pembayarannya error kemarin. Sekarang sudah bisa, terima kasih.',
            'comment', 'positive', 'complaint', false, false, 'done', 48, RoleName::Operator, 0],
        ['Sudah saya hubungi lewat WhatsApp, mahasiswanya akan datang ke kantor.',
            'manual', 'neutral', 'other', false, false, 'replied', 52, RoleName::Pic, 0],
    ];

    public function run(): void
    {
        // One user per role, so the demo lands in a real person's queue.
        $owners = [];

        foreach ([RoleName::Operator, RoleName::Pic] as $role) {
            $owners[$role->value] = User::withRole($role)->orderBy('id')->pluck('id')->all();
        }

        foreach (self::SAMPLES as $index => $sample) {
            [$text, $type, $sentiment, $intent, $urgent, $needsReply, $status, $hoursAgo, $role, $slot] = $sample;

            $assignee = $role !== null ? ($owners[$role->value][$slot] ?? $owners[$role->value][0] ?? null) : null;

            // Skip rather than assign to nobody: an example in no one's queue
            // teaches nothing.
            if ($role !== null && $assignee === null) {
                continue;
            }

            $occurredAt = now()->subHours($hoursAgo);

            Interaction::updateOrCreate(
                [
                    'channel' => SocialPlatform::Instagram->value,
                    'external_id' => self::PREFIX.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                ],
                [
                    'type' => $type,
                    'direction' => 'inbound',
                    'author_handle' => 'demo_mahasiswa'.($index + 1),
                    'author_name' => 'Contoh Mahasiswa '.($index + 1),
                    'text' => $text,
                    'occurred_at' => $occurredAt,

                    'sentiment' => $sentiment,
                    'intent' => $intent,
                    'is_urgent' => $urgent,
                    'urgency_score' => $urgent ? 85 : 20,
                    'needs_reply' => $needsReply,
                    'lead_potential' => $intent === Intent::Question->value ? 60 : 10,
                    'ai_model' => 'contoh-data',
                    'ai_confidence' => 90,
                    'ai_classified_at' => $occurredAt->copy()->addMinutes(5),

                    'status' => $status,
                    'assigned_to' => $assignee,
                    // An in-progress item has been picked up; a new one has not.
                    'first_response_at' => in_array($status, ['in_progress', 'replied', 'done'], true)
                        ? $occurredAt->copy()->addHour()
                        : null,
                    'resolved_at' => in_array($status, ['replied', 'done'], true)
                        ? $occurredAt->copy()->addHours(2)
                        : null,
                    'resolved_by' => in_array($status, ['replied', 'done'], true) ? $assignee : null,
                ],
            );
        }

        $mine = Interaction::where('external_id', 'like', self::PREFIX.'%')
            ->whereNotNull('assigned_to')
            ->count();

        $this->command?->info("  {$mine} contoh interaksi ditugaskan, ".
            (count(self::SAMPLES) - $mine).' dibiarkan tanpa petugas.');
    }
}
