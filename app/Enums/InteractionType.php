<?php

namespace App\Enums;

/**
 * The shape of the interaction, independent of which network it came from.
 * `channel` says where (Instagram, TikTok…), this says what.
 */
enum InteractionType: string
{
    case Comment = 'comment';
    case DirectMessage = 'dm';
    case Mention = 'mention';
    case Reply = 'reply';
    /** Typed in by an operator — the fallback for channels with no usable API. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Comment => 'Komentar',
            self::DirectMessage => 'Pesan (DM)',
            self::Mention => 'Mention',
            self::Reply => 'Balasan',
            self::Manual => 'Input Manual',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Comment => 'message',
            self::DirectMessage => 'send',
            self::Mention => 'at-sign',
            self::Reply => 'reply',
            self::Manual => 'edit',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
