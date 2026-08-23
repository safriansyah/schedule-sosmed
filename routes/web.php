<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DatasetItemController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Content — RESTful, plus the two workflow transitions a creative owns.
    Route::resource('contents', ContentController::class);
    Route::post('contents/{content}/submit', [ContentController::class, 'submit'])->name('contents.submit');
    Route::post('contents/{content}/cancel', [ContentController::class, 'cancel'])->name('contents.cancel');
    Route::post('contents/{content}/retry', [ContentController::class, 'retry'])->name('contents.retry');

    // Curator queue
    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/{content}', [ApprovalController::class, 'store'])->name('approvals.store');

    // Verifier queue
    Route::get('verifications', [VerificationController::class, 'index'])->name('verifications.index');
    Route::post('verifications/{content}', [VerificationController::class, 'store'])->name('verifications.store');

    // Period-over-period analytics and posting-time insights
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // Manual data sync — throttled to protect the API quota
    Route::post('sync', [SyncController::class, 'store'])->middleware('throttle:6,1')->name('sync.now');

    // Live performance of connected accounts and their posts
    Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    // Static paths must be registered before the {media} wildcard.
    Route::get('monitoring/comments', [MonitoringController::class, 'comments'])->name('monitoring.comments');
    Route::get('monitoring/profile/{username}', [MonitoringController::class, 'profile'])
        ->where('username', '[A-Za-z0-9._]+')->name('monitoring.profile');
    Route::get('monitoring/{media}', [MonitoringController::class, 'show'])->name('monitoring.show');

    // Calendar — every role may look; only ManageCalendar may drag events.
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('calendar/events', [CalendarController::class, 'events'])->name('calendar.events');
    Route::patch('calendar/{content}', [CalendarController::class, 'move'])->name('calendar.move');

    // Calendar notes / reminders / deadlines
    Route::post('calendar/notes', [CalendarEventController::class, 'store'])->name('calendar.notes.store');
    Route::put('calendar/notes/{event}', [CalendarEventController::class, 'update'])->name('calendar.notes.update');
    Route::delete('calendar/notes/{event}', [CalendarEventController::class, 'destroy'])->name('calendar.notes.destroy');

    // Personal settings — available to everyone
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');

    // User management
    Route::resource('users', UserController::class)->except('show');

    // Connected social accounts (credentials live here, encrypted)
    Route::resource('accounts', SocialAccountController::class)->except('show');
    Route::post('accounts/{account}/verify', [SocialAccountController::class, 'verify'])->name('accounts.verify');

    /*
    | UT Analytic Sosmed — bulk social-account datasets uploaded as JSON,
    | validated, then analysed. Viewing is open to every role; uploading and
    | editing requires the ManageDatasets permission (checked in the requests).
    */
    Route::get('datasets', [DatasetController::class, 'index'])->name('datasets.index');
    Route::post('datasets', [DatasetController::class, 'store'])->name('datasets.store');

    Route::prefix('datasets/{dataset}')->group(function () {
        Route::get('/', [DatasetController::class, 'show'])->name('datasets.show');
        Route::put('/', [DatasetController::class, 'update'])->name('datasets.update');
        Route::delete('/', [DatasetController::class, 'destroy'])->name('datasets.destroy');
        Route::get('table', [DatasetController::class, 'table'])->name('datasets.table');
        Route::get('status', [DatasetController::class, 'status'])->name('datasets.status');
        Route::get('export', [DatasetController::class, 'export'])->name('datasets.export');
        Route::post('replace', [DatasetController::class, 'replace'])->name('datasets.replace');

        Route::post('items', [DatasetItemController::class, 'store'])->name('datasets.items.store');
        Route::put('items/{item}', [DatasetItemController::class, 'update'])->name('datasets.items.update');
        Route::delete('items/{item}', [DatasetItemController::class, 'destroy'])->name('datasets.items.destroy');
        Route::post('items/bulk-delete', [DatasetItemController::class, 'bulkDestroy'])->name('datasets.items.bulkDestroy');
    });

    // Personal notification inbox
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('notifications/{id}', [NotificationController::class, 'read'])->name('notifications.read');

    // Audit trail
    Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
});
