<?php

namespace App\Enums;

/**
 * Where a ticket came from.
 *
 * Kept separate from `channel` on interactions: a ticket may originate from an
 * Instagram comment, from the imported student list, or from an operator
 * typing it in — and only the first of those is a social channel.
 */
enum TicketSource: string
{
    case Instagram = 'instagram';
    case Tiktok = 'tiktok';
    case Youtube = 'youtube';
    case Whatsapp = 'whatsapp';
    case StudentImport = 'import_mahasiswa';
    case Manual = 'manual';
    case Other = 'other';

    // Antrian layanan tatap muka. Only ever set by "Add Ticket" on a guest
    // book entry, never chosen by hand — see manualOptions().
    case GuestBook = 'buku_tamu';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Tiktok => 'TikTok',
            self::Youtube => 'YouTube',
            self::Whatsapp => 'WhatsApp',
            self::StudentImport => 'Import Mahasiswa',
            self::Manual => 'Manual',
            self::Other => 'Lainnya',
            self::GuestBook => 'Buku Tamu / Antrian',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Instagram => 'instagram',
            self::Tiktok => 'tiktok',
            self::Youtube => 'youtube',
            self::Whatsapp => 'whatsapp',
            self::StudentImport => 'upload',
            self::Manual => 'edit',
            self::Other => 'hash',
            self::GuestBook => 'id-card',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Instagram, self::Tiktok, self::Youtube => 'badge-pink',
            self::Whatsapp => 'badge-green',
            self::StudentImport => 'badge-cyan',
            self::Manual => 'badge-blue',
            self::Other => 'badge-slate',
            self::GuestBook => 'badge-violet',
        };
    }

    /** Sources that carry a social-media reference block on the ticket. */
    public function isSocial(): bool
    {
        return in_array($this, [self::Instagram, self::Tiktok, self::Youtube, self::Whatsapp], true);
    }

    /** Maps an interaction's channel onto a ticket source. */
    public static function fromChannel(SocialPlatform $channel): self
    {
        return self::tryFrom($channel->value) ?? self::Other;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }

    /**
     * The family a source belongs to, for grouped pickers and reports: walk-in
     * queue, social comments, the student list, or everything else.
     */
    public function group(): string
    {
        return match (true) {
            $this === self::GuestBook => 'Buku Tamu / Antrian',
            $this->isSocial() => 'Komentar Sosial Media',
            $this === self::StudentImport => 'Mahasiswa',
            default => 'Lainnya',
        };
    }

    /**
     * Options grouped by family: [group => [value => label]].
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(bool $manualOnly = false): array
    {
        $groups = [];

        foreach (self::cases() as $source) {
            if ($manualOnly && $source === self::GuestBook) {
                continue;
            }

            $groups[$source->group()][$source->value] = $source->label();
        }

        $order = ['Buku Tamu / Antrian', 'Komentar Sosial Media', 'Mahasiswa', 'Lainnya'];

        return array_replace(array_intersect_key(array_flip($order), $groups), $groups);
    }

    /**
     * Sources an operator may pick when typing a ticket in. Buku Tamu is left
     * out: such a ticket only exists through "Add Ticket" on a queue entry,
     * which records the link back to it.
     *
     * @return array<string, string>
     */
    public static function manualOptions(): array
    {
        return array_diff_key(self::options(), [self::GuestBook->value => true]);
    }
}
