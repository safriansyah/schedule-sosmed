<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a ticket's hand-over history.
 *
 * Written every time the assignee changes, including back to nobody, so
 * "who was holding this in October" has an answer.
 */
class TicketAssignment extends Model
{
    protected $fillable = [
        'ticket_id', 'from_user_id', 'to_user_id', 'assigned_by', 'note',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** Human sentence for the timeline. */
    public function describe(): string
    {
        $to = $this->toUser?->name;
        $from = $this->fromUser?->name;

        return match (true) {
            $to === null => 'Penugasan dilepas'.($from ? " dari {$from}" : ''),
            $from === null => "Ditugaskan ke {$to}",
            default => "Dialihkan dari {$from} ke {$to}",
        };
    }
}
