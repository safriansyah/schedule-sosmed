<?php

namespace App\Enums;

/** Decision recorded by a curator (approval) or verifier (verification). */
enum ApprovalAction: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revision = 'revision';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Revision => 'Minta Revisi',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Approved => 'badge-green',
            self::Rejected => 'badge-red',
            self::Revision => 'badge-pink',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Approved => 'check-circle',
            self::Rejected => 'x',
            self::Revision => 'rotate',
        };
    }
}
