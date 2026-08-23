<?php

namespace App\Enums;

/**
 * Supported social networks. Phase one ships Instagram; the rest are declared
 * so accounts, publishers and analytics can be added without schema changes.
 */
enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Twitter = 'twitter';
    case LinkedIn = 'linkedin';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube',
            self::Twitter => 'X (Twitter)',
            self::LinkedIn => 'LinkedIn',
            self::WhatsApp => 'WhatsApp',
        };
    }

    public function icon(): string
    {
        return $this->value === 'twitter' ? 'twitter' : $this->value;
    }

    /** Brand colour — badges, charts, calendar dots. */
    public function color(): string
    {
        return match ($this) {
            self::Instagram => '#e1306c',
            self::Facebook => '#1877f2',
            self::TikTok => '#00c4bd',
            self::YouTube => '#ff0000',
            self::Twitter => '#0f172a',
            self::LinkedIn => '#0a66c2',
            self::WhatsApp => '#25d366',
        };
    }

    /** Platforms that are actually wired up for publishing today. */
    public function isImplemented(): bool
    {
        return $this === self::Instagram;
    }

    /** Whether the platform requires media on every post. */
    public function requiresMedia(): bool
    {
        return in_array($this, [self::Instagram, self::TikTok, self::YouTube], true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $p) => [$p->value => $p->label()])
            ->all();
    }
}
