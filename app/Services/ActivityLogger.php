<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Single entry point for the audit trail. Every workflow transition and
 * notable mutation is recorded here so Monitoring can replay what happened.
 */
class ActivityLogger
{
    public function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
    ): Activity {
        return Activity::create([
            'user_id' => Auth::id(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    /** Convenience wrapper for status changes. */
    public function logStatusChange(Model $subject, string $from, string $to, ?string $note = null): Activity
    {
        return $this->log(
            action: 'content.status_changed',
            description: "Status berubah dari {$from} menjadi {$to}",
            subject: $subject,
            properties: array_filter(['from' => $from, 'to' => $to, 'note' => $note]),
        );
    }
}
