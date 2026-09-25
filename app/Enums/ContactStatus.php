<?php

namespace App\Enums;

/**
 * Where a person stands with us.
 *
 * NonAgent → Candidate → Agent is the recruitment path: anyone who comments is
 * a NonAgent, someone consistently positive gets flagged Candidate by an
 * operator, and the "Jadikan Agent" button promotes them to Agent.
 */
enum ContactStatus: string
{
    case NonAgent = 'non_agent';
    case Candidate = 'candidate';
    case Agent = 'agent';
    case Blacklist = 'blacklist';

    public function label(): string
    {
        return match ($this) {
            self::NonAgent => 'Non-Agent',
            self::Candidate => 'Calon Agent',
            self::Agent => 'Agent',
            self::Blacklist => 'Blacklist',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::NonAgent => 'badge-slate',
            self::Candidate => 'badge-amber',
            self::Agent => 'badge-green',
            self::Blacklist => 'badge-red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NonAgent => 'user',
            self::Candidate => 'user-plus',
            self::Agent => 'badge-check',
            self::Blacklist => 'ban',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
