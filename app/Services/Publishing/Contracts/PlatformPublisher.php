<?php

namespace App\Services\Publishing\Contracts;

use App\Enums\SocialPlatform;
use App\Models\Schedule;

/**
 * A publisher turns one Schedule row into a live post on its platform.
 *
 * Implementations receive the account (with its own credentials) via the
 * Schedule, so multiple accounts on the same platform are supported.
 */
interface PlatformPublisher
{
    public function platform(): SocialPlatform;

    /**
     * Publish and return the platform-side result.
     *
     * @return array{external_id: string, permalink: ?string}
     *
     * @throws \RuntimeException when publishing fails
     */
    public function publish(Schedule $schedule, string $caption): array;
}
