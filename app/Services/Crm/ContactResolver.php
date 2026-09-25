<?php

namespace App\Services\Crm;

use App\Enums\SocialPlatform;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Interaction;
use App\Services\Media\RemoteImageCache;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns "a comment from @budi on Instagram" into "contact UT-000142".
 *
 * This is what makes the whole CRM work: every inbound message resolves to a
 * person, so the inbox can show at a glance whether the commenter is an agent,
 * what else they have said, and who owns them.
 *
 * Resolution is best-effort and never throws into the caller — a comment that
 * cannot be matched is still stored with `contact_id` null and can be linked
 * later by hand or by a re-run.
 */
class ContactResolver
{
    public function __construct(private readonly RemoteImageCache $images) {}

    /**
     * Find the contact behind one channel identity, creating it if new.
     *
     * @param  array{name?:string|null, avatar?:string|null, verified?:bool}  $profile
     */
    public function resolve(
        SocialPlatform $channel,
        ?string $handle,
        ?string $externalId = null,
        array $profile = [],
    ): ?Contact {
        $handle = $this->cleanHandle($handle);

        if (blank($handle) && blank($externalId)) {
            return null;
        }

        $identity = $this->findIdentity($channel, $handle, $externalId);

        if ($identity) {
            $contact = $this->contactBehind($identity);

            // Identitas ada tapi kontaknya sudah lenyap sama sekali: baris ini
            // tinggal puing yang justru memblokir pembuatan kontak baru, karena
            // (channel, handle) unik. Buang, lalu perlakukan sebagai orang baru.
            if ($contact === null) {
                $identity->delete();

                return $this->create($channel, $handle, $externalId, $profile);
            }

            return $this->refresh($identity, $contact, $handle, $externalId, $profile);
        }

        return $this->create($channel, $handle, $externalId, $profile);
    }

    /** Resolve straight from an interaction — the common path in the sync. */
    public function resolveFor(Interaction $interaction): ?Contact
    {
        $contact = $this->resolve(
            $interaction->channel,
            $interaction->author_handle,
            null,
            [
                'name' => $interaction->author_name,
                'avatar' => $interaction->author_avatar,
                'verified' => (bool) $interaction->author_verified,
            ],
        );

        if ($contact && $interaction->contact_id !== $contact->id) {
            $interaction->forceFill(['contact_id' => $contact->id])->save();
        }

        // Someone leaving their number in a public comment is a strong lead
        // signal — capture it while we are here.
        if ($contact && blank($contact->phone_e164)) {
            $this->attachPhoneFromText($contact, $interaction->text);
        }

        return $contact;
    }

    /** Find or create a contact from a phone number alone (WA, event lists, imports). */
    public function resolveByPhone(?string $rawPhone, ?string $name = null): ?Contact
    {
        $phone = PhoneNumber::normalize($rawPhone);

        if ($phone === null) {
            return null;
        }

        $existing = Contact::where('phone_e164', $phone)->first();

        if ($existing) {
            if (blank($existing->full_name) && filled($name)) {
                $existing->forceFill(['full_name' => $name])->save();
            }

            return $this->canonical($existing);
        }

        return DB::transaction(function () use ($phone, $name) {
            $contact = $this->createContact([
                'display_name' => $name,
                'full_name' => $name,
                'phone_e164' => $phone,
            ]);

            ContactIdentity::create([
                'contact_id' => $contact->id,
                'channel' => SocialPlatform::WhatsApp->value,
                'handle' => $phone,
                'is_primary' => true,
            ]);

            return $contact;
        });
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    private function findIdentity(SocialPlatform $channel, ?string $handle, ?string $externalId): ?ContactIdentity
    {
        // The platform's numeric id is preferred: usernames get changed, and
        // matching on a stale username would attach a message to the wrong
        // person.
        if (filled($externalId)) {
            $byId = ContactIdentity::where('channel', $channel->value)
                ->where('external_id', $externalId)
                ->first();

            if ($byId) {
                return $byId;
            }
        }

        if (blank($handle)) {
            return null;
        }

        return ContactIdentity::where('channel', $channel->value)
            ->where('handle', $handle)
            ->first();
    }

    /**
     * Kontak di balik sebuah identitas, termasuk yang terhapus-lunak.
     *
     * Relasi biasa memulangkan null untuk kontak terhapus — sementara baris
     * identitasnya tetap ada dan tetap memegang kunci unik (channel, handle).
     * Tanpa penanganan ini, orang yang kontaknya pernah dihapus lalu
     * berkomentar lagi membuat proses berhenti dengan TypeError.
     */
    private function contactBehind(ContactIdentity $identity): ?Contact
    {
        return $identity->contact()->withTrashed()->first();
    }

    /** Keep the identity and contact current with what the platform now shows. */
    private function refresh(
        ContactIdentity $identity,
        Contact $found,
        ?string $handle,
        ?string $externalId,
        array $profile,
    ): Contact {
        $identityChanges = array_filter([
            'handle' => blank($identity->handle) ? $handle : null,
            'external_id' => blank($identity->external_id) ? $externalId : null,
        ]);

        if ($identityChanges) {
            $identity->forceFill($identityChanges)->save();
        }

        // Orangnya kembali — pulihkan riwayatnya, jangan buat kontak kembar.
        if ($found->trashed()) {
            $found->restore();
        }

        $contact = $this->canonical($found);

        $contact->forceFill(array_filter([
            'display_name' => blank($contact->display_name) ? ($profile['name'] ?? null) : null,
            'avatar_url' => $profile['avatar'] ?? null,
            // Cached while the signed link still resolves; see RemoteImageCache.
            'avatar_path' => $this->cacheAvatar($profile['avatar'] ?? null, $identity->handle ?? $contact->code),
            'last_seen_at' => now(),
        ]))->save();

        return $contact;
    }

    private function create(SocialPlatform $channel, ?string $handle, ?string $externalId, array $profile): Contact
    {
        return DB::transaction(function () use ($channel, $handle, $externalId, $profile) {
            $contact = $this->createContact([
                'display_name' => $profile['name'] ?? $handle,
                'avatar_url' => $profile['avatar'] ?? null,
                'avatar_path' => $this->cacheAvatar($profile['avatar'] ?? null, $handle ?? $externalId ?? ''),
                // WhatsApp identities ARE the phone number.
                'phone_e164' => $channel === SocialPlatform::WhatsApp
                    ? PhoneNumber::normalize($handle)
                    : null,
            ]);

            ContactIdentity::create([
                'contact_id' => $contact->id,
                'channel' => $channel->value,
                'handle' => $handle,
                'external_id' => $externalId,
                'is_primary' => true,
                'verified_at' => ($profile['verified'] ?? false) ? now() : null,
            ]);

            return $contact;
        });
    }

    /**
     * Insert a contact, retrying once if the sequential code collided with a
     * concurrent insert. See Contact::nextCode() for why that can happen.
     */
    private function createContact(array $attributes): Contact
    {
        $attributes += ['first_seen_at' => now(), 'last_seen_at' => now()];

        try {
            return Contact::create($attributes);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            return Contact::create($attributes + ['code' => Contact::nextCode()]);
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, [1062, 19], true);
    }

    /** Follow a merge pointer so callers never hand back a duplicate row. */
    private function canonical(Contact $contact): Contact
    {
        $seen = [];

        while ($contact->merged_into_id && ! isset($seen[$contact->id])) {
            $seen[$contact->id] = true;
            // withTrashed: penunjuk merge bisa mengarah ke kontak yang kemudian
            // dihapus; berhenti di kontak sekarang lebih baik daripada null.
            $contact = Contact::withTrashed()->find($contact->merged_into_id) ?? $contact;
        }

        return $contact;
    }

    private function attachPhoneFromText(Contact $contact, ?string $text): void
    {
        $phone = PhoneNumber::extractFrom($text);

        // Skip if another contact already owns the number — the phone column is
        // unique, and guessing a merge here would be worse than leaving it.
        if ($phone === null || Contact::where('phone_e164', $phone)->exists()) {
            return;
        }

        $contact->forceFill(['phone_e164' => $phone])->save();

        ContactIdentity::firstOrCreate(
            ['channel' => SocialPlatform::WhatsApp->value, 'handle' => $phone],
            ['contact_id' => $contact->id],
        );
    }

    /** Best-effort: a missing avatar must never cost us the contact. */
    private function cacheAvatar(?string $url, string $key): ?string
    {
        if (blank($url) || blank($key)) {
            return null;
        }

        return $this->images->store($url, 'avatars', mb_strtolower($key));
    }

    private function cleanHandle(?string $handle): ?string
    {
        if (blank($handle)) {
            return null;
        }

        return mb_strtolower(ltrim(trim($handle), '@')) ?: null;
    }
}
