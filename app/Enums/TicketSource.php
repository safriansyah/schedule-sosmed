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
}
