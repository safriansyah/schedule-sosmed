<?php

namespace App\Enums;

/**
 * What a ticket is worth to us, apart from how far along it is.
 *
 * Deliberately separate from TicketStatus: status says "where is this in the
 * queue", flag says "is there a prospective student at the other end". A
 * closed ticket can still have been a Lead, and that is exactly the number
 * the acquisition side wants to count.
 *
 * Netral is the default so nothing is ever silently claimed as a lead.
 */
enum TicketFlag: string
{
    case Netral = 'netral';
    case Lead = 'lead';

    public function label(): string
    {
        return match ($this) {
            self::Netral => 'Netral',
            self::Lead => 'Lead',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Netral => 'badge-slate',
            self::Lead => 'badge-violet',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Netral => 'minus',
            self::Lead => 'target',
        };
    }

    /**
     * The flag a new ticket from a comment starts on.
     *
     * Reuses the classifier's existing lead score rather than inventing a
     * second opinion — 50 is the same threshold the inbox already uses to show
     * its "Potensi" badge. An operator can always change it by hand.
     */
    public static function fromLeadPotential(?int $score): self
    {
        return (int) $score >= 50 ? self::Lead : self::Netral;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $f) => [$f->value => $f->label()])->all();
    }
}
