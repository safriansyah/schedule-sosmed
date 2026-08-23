<?php

namespace App\Policies;

use App\Enums\ContentStatus;
use App\Enums\Permission;
use App\Models\Content;
use App\Models\User;

class ContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewAnyContent);
    }

    public function view(User $user, Content $content): bool
    {
        return $user->hasPermission(Permission::ViewContent);
    }

    public function create(User $user): bool
    {
        return ! $user->isReadOnly() && $user->hasPermission(Permission::CreateContent);
    }

    /** Creatives may only edit their own work, and only while it is open. */
    public function update(User $user, Content $content): bool
    {
        if ($user->isReadOnly() || ! $user->hasPermission(Permission::UpdateContent)) {
            return false;
        }

        return $content->isEditableBy($user);
    }

    public function delete(User $user, Content $content): bool
    {
        if ($user->isReadOnly() || ! $user->hasPermission(Permission::DeleteContent)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $content->created_by === $user->id
            && $content->status->isEditableByCreative();
    }

    /** Send a draft into the approval queue. */
    public function submit(User $user, Content $content): bool
    {
        if (! $user->hasPermission(Permission::SubmitContent)) {
            return false;
        }

        return $content->status->isEditableByCreative()
            && ($user->isSuperAdmin() || $content->created_by === $user->id);
    }

    /** Curator decision: approve / reject / request revision. */
    public function decideApproval(User $user, Content $content): bool
    {
        return $user->hasPermission(Permission::DecideApproval)
            && $content->status === ContentStatus::WaitingApproval;
    }

    /** Verifier decision — only after the curator approved. */
    public function decideVerification(User $user, Content $content): bool
    {
        return $user->hasPermission(Permission::DecideVerification)
            && $content->status === ContentStatus::WaitingVerification;
    }

    public function publish(User $user, Content $content): bool
    {
        return $user->hasPermission(Permission::PublishContent)
            && in_array($content->status, [
                ContentStatus::Verified,
                ContentStatus::Scheduled,
                ContentStatus::Failed,
            ], true);
    }

    /**
     * Retry a failed publish. The owner may retry their own content — they
     * understand the cause (media/caption) best — alongside anyone who can
     * publish.
     */
    public function retry(User $user, Content $content): bool
    {
        if ($content->status !== ContentStatus::Failed) {
            return false;
        }

        return $user->hasPermission(Permission::PublishContent)
            || $content->created_by === $user->id;
    }

    /**
     * Cancel content anywhere before it goes out. The creator can pull back
     * their own in-flight content; a super admin can cancel anything.
     */
    public function cancel(User $user, Content $content): bool
    {
        if ($content->status->isFinal()) {
            return false;
        }

        return $user->isSuperAdmin() || $content->created_by === $user->id;
    }

    public function restore(User $user, Content $content): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Content $content): bool
    {
        return $user->isSuperAdmin();
    }
}
