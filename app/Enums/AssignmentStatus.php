<?php

namespace App\Enums;

/**
 * How far a student row has travelled through the hand-out process.
 *
 * Everything lands on Unassigned at import — never straight to an operator.
 * The admin decides who gets what, which is the whole point of the module.
 */
enum AssignmentStatus: string
{
    case Unassigned = 'belum_assigned';
    case Assigned = 'assigned';
    case FollowUp = 'follow_up';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Unassigned => 'Belum Assigned',
            self::Assigned => 'Assigned',
            self::FollowUp => 'Follow Up',
            self::Resolved => 'Selesai',
            self::Closed => 'Ditutup',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Unassigned => 'badge-slate',
            self::Assigned => 'badge-blue',
            self::FollowUp => 'badge-violet',
            self::Resolved => 'badge-green',
            self::Closed => 'badge-amber',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Unassigned => 'inbox',
            self::Assigned => 'user-plus',
            self::FollowUp => 'phone',
            self::Resolved => 'check-circle',
            self::Closed => 'ban',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
