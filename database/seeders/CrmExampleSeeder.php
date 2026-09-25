<?php

namespace Database\Seeders;

use App\Enums\ContactStatus;
use App\Enums\FollowUpAction;
use App\Enums\FollowUpOutcome;
use App\Enums\InteractionStatus;
use App\Enums\InteractionType;
use App\Enums\RegionLevel;
use App\Enums\RoleName;
use App\Enums\SocialPlatform;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Interaction;
use App\Models\Region;
use App\Models\User;
use App\Services\Crm\ContactResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Worked examples for the CRM, so the screens can be judged with something in
 * them rather than empty.
 *
 * Everything created here is prefixed `contoh_` / marked in its notes, and
 * `crm:examples --clear` removes exactly these rows and nothing else — so it
 * can go in without worrying about untangling it from real data later.
 *
 * The examples are deliberately awkward rather than tidy: sarcasm, a
 * reputational attack, a number typed into a public comment. Seed data that
 * only shows the happy path teaches nothing about whether the system works.
 */
class CrmExampleSeeder extends Seeder
{
    /** Marks every row this seeder owns, so --clear can find them again. */
    public const TAG = '[contoh]';

    public function run(): void
    {
        $operator = User::withRole(RoleName::Operator)->first() ?? User::first();
        $resolver = app(ContactResolver::class);

        $agents = $this->agents($resolver, $operator);
        $this->interactions($resolver, $operator);

        $this->command?->info('Contoh dibuat: '.count($agents).' agent + interaksi.');
    }

    /* -----------------------------------------------------------------
     | Two agents, each showing a different route in
     * ----------------------------------------------------------------- */

    /** @return array<int, Contact> */
    private function agents(ContactResolver $resolver, User $operator): array
    {
        $examples = [
            [
                'handle' => 'contoh_sitirahayu',
                'channel' => SocialPlatform::Instagram,
                'name' => 'Siti Rahayu',
                'phone' => '6281234500001',
                'region' => '34.04.05.2003',   // Caturtunggal, Depok, Sleman
                'score' => 85,
                'note' => 'Alumni angkatan 2019. Aktif membalas pertanyaan calon mahasiswa '
                    .'di kolom komentar sebelum diangkat jadi agent.',
                'extra' => [SocialPlatform::TikTok->value => 'contoh_sitirahayu_tt'],
            ],
            [
                'handle' => 'contoh_budipratama',
                'channel' => SocialPlatform::WhatsApp,
                'name' => 'Budi Pratama',
                'phone' => '6281234500002',
                'region' => '34.71.01',        // Mantrijeron, Kota Yogyakarta
                'score' => 70,
                'note' => 'Masuk lewat pendataan event, bukan dari komentar. '
                    .'Menangani wilayah Kota Yogyakarta.',
                'extra' => [SocialPlatform::Instagram->value => 'contoh_budipratama'],
            ],
        ];

        $created = [];

        foreach ($examples as $example) {
            // Built through the real resolver, not raw inserts — so the example
            // exercises the same path a live comment would.
            $contact = $example['channel'] === SocialPlatform::WhatsApp
                ? $resolver->resolveByPhone($example['phone'], $example['name'])
                : $resolver->resolve($example['channel'], $example['handle'], null, ['name' => $example['name']]);

            if (! $contact) {
                continue;
            }

            $contact->forceFill([
                'full_name' => $example['name'],
                'phone_e164' => $example['phone'],
                'region_id' => Region::where('code', $example['region'])->value('id'),
                'potential_score' => $example['score'],
                'owner_id' => $operator->id,
                'notes' => self::TAG.' '.$example['note'],
            ])->save();

            foreach ($example['extra'] as $channel => $handle) {
                ContactIdentity::firstOrCreate(
                    ['channel' => $channel, 'handle' => $handle],
                    ['contact_id' => $contact->id],
                );
            }

            // Promoted properly, so the agent code and timestamp are issued the
            // same way the button issues them.
            if (! $contact->isAgent()) {
                $contact->promoteToAgent($operator);
            }

            $created[] = $contact;
        }

        return $created;
    }

    /* -----------------------------------------------------------------
     | Interactions covering every case the inbox has to handle
     * ----------------------------------------------------------------- */

    private function interactions(ContactResolver $resolver, User $operator): void
    {
        $samples = [
            [
                'handle' => 'contoh_warga_marah',
                'name' => 'Andi Kurniawan',
                'channel' => SocialPlatform::Instagram,
                'type' => InteractionType::Comment,
                'text' => 'kampus ini penipuan, ijazahnya gak diakui. saya viralkan biar semua tau!',
                'hours_ago' => 5,
                'status' => InteractionStatus::New,
            ],
            [
                'handle' => 'contoh_calon_mhs',
                'name' => 'Rina Wulandari',
                'channel' => SocialPlatform::Instagram,
                'type' => InteractionType::Comment,
                'text' => 'min mau tanya, pendaftaran gelombang 2 sampai kapan ya? '
                    .'terus biaya per semesternya berapa? wa saya 0812-3450-0009 ya kak',
                'hours_ago' => 3,
                'status' => InteractionStatus::New,
            ],
            [
                'handle' => 'contoh_sarkas',
                'name' => 'Dewi Anggraini',
                'channel' => SocialPlatform::Instagram,
                'type' => InteractionType::Comment,
                'text' => 'wah mantap ya pelayanannya, seminggu ditanya gak dibales sama sekali. keren',
                'hours_ago' => 30,
                'status' => InteractionStatus::New,
            ],
            [
                'handle' => 'contoh_tiktok_dm',
                'name' => 'Fajar Nugroho',
                'channel' => SocialPlatform::TikTok,
                'type' => InteractionType::DirectMessage,
                'text' => 'kak saya lulusan SMK, bisa ambil jurusan manajemen gak? syaratnya apa aja',
                'hours_ago' => 8,
                'status' => InteractionStatus::New,
            ],
            [
                'handle' => 'contoh_pujian',
                'name' => 'Nur Aisyah',
                'channel' => SocialPlatform::Instagram,
                'type' => InteractionType::Comment,
                'text' => 'makasih banyak kak adminnya ramah bangettt, dijawab lengkap 🙏',
                'hours_ago' => 20,
                'status' => InteractionStatus::Replied,
                'answered_after_hours' => 1,
                'follow_up' => [
                    'action' => FollowUpAction::Replied,
                    'channel' => 'dm',
                    'text' => 'Terima kasih kembali. Informasi pendaftaran sudah dikirim lewat DM.',
                    'outcome' => FollowUpOutcome::Positive,
                ],
            ],
            [
                'handle' => 'contoh_spam',
                'name' => null,
                'channel' => SocialPlatform::Instagram,
                'type' => InteractionType::Comment,
                'text' => 'SITUS GACOR MAXWIN CEK BIO SEKARANG JUGA DEPO 10K WD LANCAR',
                'hours_ago' => 12,
                'status' => InteractionStatus::New,
            ],
        ];

        foreach ($samples as $sample) {
            $occurredAt = now()->subHours($sample['hours_ago']);

            $interaction = Interaction::updateOrCreate(
                ['channel' => $sample['channel']->value, 'external_id' => 'contoh-'.$sample['handle']],
                [
                    'type' => $sample['type']->value,
                    'direction' => 'inbound',
                    'author_handle' => $sample['handle'],
                    'author_name' => $sample['name'],
                    'text' => $sample['text'],
                    'occurred_at' => $occurredAt,
                    'status' => $sample['status']->value,
                    'like_count' => random_int(0, 12),
                    // Left unclassified on purpose: the scheduled run picks
                    // these up, which also demonstrates the classifier working.
                    'ai_classified_at' => null,
                    'first_response_at' => isset($sample['answered_after_hours'])
                        ? $occurredAt->copy()->addHours($sample['answered_after_hours'])
                        : null,
                ],
            );

            $resolver->resolveFor($interaction);

            if ($followUp = $sample['follow_up'] ?? null) {
                $interaction->followUps()->firstOrCreate(
                    ['user_id' => $operator->id, 'action' => $followUp['action']->value],
                    [
                        'channel_used' => $followUp['channel'],
                        'response_text' => $followUp['text'],
                        'outcome' => $followUp['outcome']->value,
                    ],
                );
            }
        }
    }

    /* -----------------------------------------------------------------
     | Removal
     * ----------------------------------------------------------------- */

    /** @return array{interactions:int, contacts:int} */
    public static function clear(): array
    {
        $interactions = Interaction::where('external_id', 'like', 'contoh-%')
            ->orWhere('author_handle', 'like', 'contoh\_%')
            ->get();

        $contactIds = $interactions->pluck('contact_id')->filter()->unique();

        Interaction::whereIn('id', $interactions->pluck('id'))->delete();

        $handles = ContactIdentity::where('handle', 'like', 'contoh\_%')->pluck('contact_id');

        $contacts = Contact::whereIn('id', $contactIds->merge($handles)->unique())
            ->orWhere('notes', 'like', self::TAG.'%')
            ->get();

        foreach ($contacts as $contact) {
            $contact->identities()->delete();
            $contact->forceDelete();
        }

        return ['interactions' => $interactions->count(), 'contacts' => $contacts->count()];
    }
}
