<?php

namespace App\Notifications;

use App\Models\Content;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One notification class for every workflow hand-off. Keeping it in a single
 * class means the payload stays consistent and the bell/list only ever has to
 * understand one shape.
 */
class WorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $event,
        public readonly Content $content,
        public readonly string $title,
        public readonly string $body,
        public readonly string $icon = 'bell',
        public readonly string $tone = 'brand',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'tone' => $this->tone,
            'content_id' => $this->content->getKey(),
            'content_title' => $this->content->title,
            'url' => route('contents.show', $this->content),
        ];
    }
}
