<?php

namespace App\Enums;

/**
 * What the message is FOR — orthogonal to sentiment.
 *
 * The distinction matters operationally: "kok mahal banget sih" is negative but
 * routine, while `Disparagement` is negative AND aimed at the institution's
 * reputation, which is what escalates a message to urgent.
 */
enum Intent: string
{
    case Question = 'question';            // "min, pendaftaran sampai kapan?"
    case Praise = 'praise';                // "keren banget kampusnya"
    case Complaint = 'complaint';          // a service problem, fixable
    case Disparagement = 'disparagement';  // attacking the institution — the urgent one
    case Spam = 'spam';                    // promo, judol, bot
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Question => 'Pertanyaan',
            self::Praise => 'Pujian',
            self::Complaint => 'Keluhan',
            self::Disparagement => 'Menjelekkan',
            self::Spam => 'Spam',
            self::Other => 'Lainnya',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Question => 'badge-blue',
            self::Praise => 'badge-green',
            self::Complaint => 'badge-amber',
            self::Disparagement => 'badge-red',
            self::Spam => 'badge-slate',
            self::Other => 'badge-slate',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Question => 'help-circle',
            self::Praise => 'heart',
            self::Complaint => 'alert',
            self::Disparagement => 'flame',
            self::Spam => 'ban',
            self::Other => 'hash',
        };
    }

    /** Intents a human is expected to answer. Spam and praise are not. */
    public function expectsReply(): bool
    {
        return in_array($this, [self::Question, self::Complaint, self::Disparagement], true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $i) => [$i->value => $i->label()])->all();
    }
}
