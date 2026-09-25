<?php

namespace App\Notifications;

use App\Models\Interaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Raised when the classifier flags a message as a reputational risk.
 *
 * The payload mirrors WorkflowNotification's shape (title/body/icon/tone/url)
 * so the existing bell and inbox render it without a single change.
 *
 * Note the deliberate restraint: this tells a human to go and look. It does
 * not act, and it is never sent straight to leadership — the classifier is
 * right most of the time, not all of the time, and a false accusation
 * forwarded upward costs more than a late one.
 */
class UrgentInteractionNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Interaction $interaction) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $who = $this->interaction->handle();
        $channel = $this->interaction->channel->label();

        return [
            'event' => 'interaction.urgent',
            'title' => "Komentar mendesak di {$channel}",
            'body' => "{$who}: \"{$this->interaction->shortText(120)}\"",
            'icon' => 'flame',
            'tone' => 'rose',
            'interaction_id' => $this->interaction->getKey(),
            'url' => route('interactions.show', $this->interaction),
        ];
    }
}
