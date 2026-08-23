<?php

namespace App\Providers;

use App\Enums\ContentStatus;
use App\Models\Content;
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
        // Actionable-count badges shown directly on the workflow menu items.
        // Each badge only surfaces where the menu itself is visible, so a
        // curator sees the approval count, a verifier the verification count,
        // and everyone the "needs revision" count on the Konten menu.
        View::composer('partials.sidebar', function ($view) {
            $badges = ['contents.index' => 0, 'approvals.index' => 0, 'verifications.index' => 0];

            if (Auth::check()) {
                $counts = Content::query()
                    ->selectRaw('status, COUNT(*) as total')
                    ->whereIn('status', [
                        ContentStatus::Revision->value,
                        ContentStatus::WaitingApproval->value,
                        ContentStatus::WaitingVerification->value,
                    ])
                    ->groupBy('status')
                    ->pluck('total', 'status');

                $badges['contents.index'] = (int) ($counts[ContentStatus::Revision->value] ?? 0);
                $badges['approvals.index'] = (int) ($counts[ContentStatus::WaitingApproval->value] ?? 0);
                $badges['verifications.index'] = (int) ($counts[ContentStatus::WaitingVerification->value] ?? 0);
            }

            $view->with('navBadges', $badges);
        });
    }
}
