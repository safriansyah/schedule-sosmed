<?php

namespace App\Enums;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Thumbnail = 'thumbnail';
    case Cover = 'cover';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Gambar',
            self::Video => 'Video',
            self::Thumbnail => 'Thumbnail',
            self::Cover => 'Cover',
        };
    }

    public function icon(): string
    {
        return $this === self::Video ? 'video' : 'image';
    }

    /** Media that counts as the actual post payload (vs. artwork). */
    public function isPayload(): bool
    {
        return in_array($this, [self::Image, self::Video], true);
    }

    public static function fromMime(?string $mime): self
    {
        return $mime && str_starts_with($mime, 'video/') ? self::Video : self::Image;
    }
}
