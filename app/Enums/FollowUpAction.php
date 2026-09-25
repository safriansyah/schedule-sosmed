<?php

namespace App\Enums;

/** What a member of staff actually did on one follow-up touch. */
enum FollowUpAction: string
{
    case Replied = 'dibalas';
    case Called = 'ditelepon';
    case Messaged = 'chat_wa';
    case SentBrochure = 'kirim_brosur';
    case Scheduled = 'dijadwalkan';
    case Met = 'ditemui';
    case NoResponse = 'tidak_respon';
    case Escalated = 'eskalasi';

    public function label(): string
    {
        return match ($this) {
            self::Replied => 'Dibalas',
            self::Called => 'Ditelepon',
            self::Messaged => 'Chat WhatsApp',
            self::SentBrochure => 'Kirim Brosur / Info',
            self::Scheduled => 'Dijadwalkan',
            self::Met => 'Ditemui Langsung',
            self::NoResponse => 'Tidak Merespon',
            self::Escalated => 'Dieskalasi',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Replied => 'reply',
            self::Called => 'phone',
            self::Messaged => 'whatsapp',
            self::SentBrochure => 'file-text',
            self::Scheduled => 'calendar',
            self::Met => 'users',
            self::NoResponse => 'clock',
            self::Escalated => 'alert',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $a) => [$a->value => $a->label()])->all();
    }
}
