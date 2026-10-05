<?php

namespace App\Services\Crm;

use App\Enums\InteractionStatus;
use App\Enums\SocialPlatform;
use App\Models\FollowUp;
use App\Models\Interaction;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up and Close on an interaction (a comment or DM).
 *
 * A conversation with one account goes: comment → follow up → they answer →
 * follow up again → … → Close. Every follow-up is a FollowUp row on the
 * interaction (the same model tickets use), so the whole exchange stays
 * readable afterwards. Close only marks the handling finished: nothing is
 * deleted, and a new follow-up on a closed interaction opens it again.
 */
class InteractionHandling
{
    public function __construct(private readonly ActivityLogger $log) {}

    /**
     * @param  array{action: string, response_text: string, channel_used?: ?string, next_action_at?: ?string, close?: bool}  $data
     */
    public function followUp(Interaction $interaction, array $data, User $actor): FollowUp
    {
        return DB::transaction(function () use ($interaction, $data, $actor) {
            $followUp = $interaction->followUps()->create([
                'user_id' => $actor->id,
                'role_at_time' => $actor->roleLabel(),
                'channel_used' => $data['channel_used'] ?? null,
                'action' => $data['action'],
                'response_text' => $data['response_text'],
                'next_action_at' => $data['next_action_at'] ?? null,
            ]);

            $interaction->forceFill([
                // Being followed up means it is being handled — including a
                // closed one the person has written back on.
                'status' => InteractionStatus::InProgress->value,
                'first_response_at' => $interaction->first_response_at ?? now(),
                'resolved_at' => null,
                'resolved_by' => null,
                // Unassigned work picked up by someone becomes theirs.
                'assigned_to' => $interaction->assigned_to ?? $actor->id,
            ])->save();

            $count = $interaction->followUps()->count();

            $this->log->log(
                'interaction.followed_up',
                "Follow up #{$count} pada interaksi {$interaction->handle()}",
                $interaction,
                ['action' => $data['action']],
            );

            if (! empty($data['close'])) {
                $this->close($interaction, $actor);
            }

            return $followUp;
        });
    }

    public function close(Interaction $interaction, User $actor): Interaction
    {
        if ($interaction->status === InteractionStatus::Closed) {
            return $interaction;
        }

        $interaction->forceFill([
            'status' => InteractionStatus::Closed->value,
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
        ])->save();

        $this->log->log('interaction.closed', "Interaksi {$interaction->handle()} ditutup (Closed)", $interaction);

        return $interaction;
    }

    public function reopen(Interaction $interaction, User $actor): Interaction
    {
        $interaction->forceFill([
            'status' => InteractionStatus::InProgress->value,
            'resolved_at' => null,
            'resolved_by' => null,
        ])->save();

        $this->log->log('interaction.reopened', "Interaksi {$interaction->handle()} dibuka kembali", $interaction);

        return $interaction;
    }

    /**
     * Close every still-open interaction from one account at once — the
     * "selesai dengan orang ini" button on the grouped inbox.
     *
     * @return int how many were closed
     */
    public function closeAccount(SocialPlatform $channel, ?string $handle, User $actor): int
    {
        $count = Interaction::query()
            ->inbound()
            ->where('channel', $channel->value)
            ->when($handle !== null, fn ($q) => $q->where('author_handle', $handle), fn ($q) => $q->whereNull('author_handle'))
            ->whereNotIn('status', [InteractionStatus::Closed->value, InteractionStatus::Ignored->value])
            ->update([
                'status' => InteractionStatus::Closed->value,
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
            ]);

        if ($count > 0) {
            $this->log->log(
                'interaction.closed',
                "Menutup {$count} interaksi dari ".($handle ? '@'.$handle : 'akun tanpa username'),
                null,
                ['channel' => $channel->value, 'handle' => $handle, 'count' => $count],
            );
        }

        return $count;
    }
}
