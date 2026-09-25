<?php

namespace App\Providers;

use App\Enums\ContentStatus;
use App\Enums\Intent;
use App\Enums\InteractionStatus;
use App\Models\Content;
use App\Models\Interaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Actionable-count badges shown directly on the nav.
        //
        // Keys match what partials/sidebar.blade.php looks up: a route name for
        // a plain link, the group's label for a collapsed group header, and
        // route+tab for a tab child (e.g. "interactions.indexurgent").
        //
        // Name, logo and favicon are read by both layouts and the sidebar, on
        // every render. Shared once here so no view has to know where they
        // come from, and cached inside Setting so it is not three queries.
        View::share('branding', app(\App\Services\SiteBranding::class));

        // Two grouped queries, not one per badge — this composer runs on every
        // page render, so it must stay cheap.
        View::composer('partials.sidebar', function ($view) {
            $view->with('navBadges', Auth::check()
                ? [...$this->contentBadges(), ...$this->interactionBadges()]
                : []);
        });
    }

    /** @return array<string, int> */
    private function contentBadges(): array
    {
        $counts = Content::query()
            ->selectRaw('status, COUNT(*) as total')
            ->whereIn('status', [
                ContentStatus::Revision->value,
                ContentStatus::WaitingApproval->value,
                ContentStatus::WaitingVerification->value,
            ])
            ->groupBy('status')
            ->pluck('total', 'status');

        $revision = (int) ($counts[ContentStatus::Revision->value] ?? 0);
        $approval = (int) ($counts[ContentStatus::WaitingApproval->value] ?? 0);
        $verification = (int) ($counts[ContentStatus::WaitingVerification->value] ?? 0);

        return [
            'contents.index' => $revision,
            'approvals.index' => $approval,
            'verifications.index' => $verification,
            // Group header: everything waiting on someone inside this group.
            'Produksi Konten' => $revision + $approval + $verification,
        ];
    }

    /** @return array<string, int> */
    private function interactionBadges(): array
    {
        $open = [InteractionStatus::New->value, InteractionStatus::InProgress->value];

        // One pass over the open inbox, bucketed with conditional sums, rather
        // than four COUNT queries.
        $row = Interaction::query()
            ->where('direction', 'inbound')
            ->whereIn('status', $open)
            ->selectRaw('
                SUM(is_urgent = 1) as urgent,
                SUM(intent = ?) as question,
                SUM(needs_reply = 1) as needs_reply,
                SUM(assigned_to = ?) as mine
            ', [Intent::Question->value, Auth::id()])
            ->first();

        $urgent = (int) ($row->urgent ?? 0);

        return [
            'interactions.indexurgent' => $urgent,
            'interactions.indexquestion' => (int) ($row->question ?? 0),
            'interactions.indexneeds_reply' => (int) ($row->needs_reply ?? 0),
            'interactions.indexmine' => (int) ($row->mine ?? 0),
            // The group header shows only what is genuinely alarming, so the
            // red dot means "reputational risk", not "there is mail".
            'Inbox Interaksi' => $urgent,
        ];
    }
}
