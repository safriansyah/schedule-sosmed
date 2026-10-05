<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DatasetItemController;
use App\Http\Controllers\GuestBookController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PublicGuestBookController;
use App\Http\Controllers\PublicTaskController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteSettingController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\StudentAssignmentController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentImportController;
use App\Http\Controllers\StudentTicketController;
use App\Http\Controllers\TaskCheckController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TicketCategoryController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketSettingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
|
| Buku Tamu / Antrian: the form a visitor fills in to take a queue number,
| and the monitor for the waiting room's TV. Neither needs a login, and
| neither exposes more than PublicGuestBookController whitelists.
|
| Submitting is throttled per IP (loosely: a whole campus Wi-Fi shares one
| address); the monitor's feed is polled every few seconds by every screen
| showing it, so it gets a far higher allowance.
|
*/
Route::prefix('guest-book')->name('guest-book.')->group(function () {
    Route::get('/', [PublicGuestBookController::class, 'create'])->name('create');
    Route::post('/', [PublicGuestBookController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('selesai', [PublicGuestBookController::class, 'done'])->name('done');
    Route::get('monitor', [PublicGuestBookController::class, 'monitor'])->name('monitor');
    Route::get('monitor/feed', [PublicGuestBookController::class, 'feed'])->middleware('throttle:600,1')->name('monitor.feed');
});

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
    // Polled by the button while a run is in flight. Cheap: one cache read.
    Route::get('sync/status', [SyncController::class, 'show'])->name('sync.status');

    // Live performance of connected accounts and their posts
    Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    // Static paths must be registered before the {media} wildcard.
    Route::get('monitoring/comments', [MonitoringController::class, 'comments'])->name('monitoring.comments');
    Route::get('monitoring/profile/{username}', [MonitoringController::class, 'profile'])
        ->where('username', '[A-Za-z0-9._]+')->name('monitoring.profile');
    Route::get('monitoring/{media}', [MonitoringController::class, 'show'])->name('monitoring.show');

    /*
    | Inbox interaksi — komentar & DM dari semua kanal, lengkap dengan hasil
    | klasifikasi AI dan riwayat penanganan.
    |
    | Static paths are registered before the {interaction} wildcard, otherwise
    | "classify" would be parsed as an interaction id.
    */
    Route::get('interactions', [InteractionController::class, 'index'])->name('interactions.index');
    Route::post('interactions/classify', [InteractionController::class, 'classify'])
        ->middleware('throttle:6,1')->name('interactions.classify');
    Route::post('interactions/bulk', [InteractionController::class, 'bulk'])->name('interactions.bulk');
    Route::get('interactions/accuracy', [InteractionController::class, 'accuracy'])->name('interactions.accuracy');
    Route::get('interactions/account', [InteractionController::class, 'account'])->name('interactions.account');
    Route::post('interactions/account/close', [InteractionController::class, 'closeAccount'])->name('interactions.account.close');
    Route::get('interactions/manual', [InteractionController::class, 'createManual'])->name('interactions.manual');
    Route::post('interactions/manual', [InteractionController::class, 'storeManual'])->name('interactions.storeManual');
    Route::get('interactions/{interaction}', [InteractionController::class, 'show'])->name('interactions.show');
    Route::post('interactions/{interaction}/override', [InteractionController::class, 'override'])
        ->name('interactions.override');
    Route::post('interactions/{interaction}/resolve-contact', [InteractionController::class, 'resolveContact'])
        ->name('interactions.resolveContact');
    Route::post('interactions/{interaction}/follow-up', [InteractionController::class, 'followUp'])->name('interactions.followUp');
    Route::post('interactions/{interaction}/close', [InteractionController::class, 'close'])->name('interactions.close');
    Route::post('interactions/{interaction}/reopen', [InteractionController::class, 'reopen'])->name('interactions.reopen');

    /*
    | Database kontak (UID) dan register agent. Data pribadi — setiap aksi
    | dijaga permission dan tercatat di audit trail.
    */
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('contacts/agents', [ContactController::class, 'agents'])->name('contacts.agents');
    Route::get('contacts/regions', [ContactController::class, 'regions'])->name('contacts.regions');
    Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::post('contacts/{contact}/agent', [ContactController::class, 'promote'])->name('contacts.promote');
    Route::delete('contacts/{contact}/agent', [ContactController::class, 'demote'])->name('contacts.demote');
    Route::post('contacts/{contact}/candidate', [ContactController::class, 'markCandidate'])
        ->name('contacts.candidate');

    // Calendar — every role may look; only ManageCalendar may drag events.
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('calendar/events', [CalendarController::class, 'events'])->name('calendar.events');
    Route::patch('calendar/{content}', [CalendarController::class, 'move'])->name('calendar.move');

    // Calendar notes / reminders / deadlines
    Route::post('calendar/notes', [CalendarEventController::class, 'store'])->name('calendar.notes.store');
    Route::put('calendar/notes/{event}', [CalendarEventController::class, 'update'])->name('calendar.notes.update');
    Route::delete('calendar/notes/{event}', [CalendarEventController::class, 'destroy'])->name('calendar.notes.destroy');

    // Personal settings — available to everyone
    // Identitas website — nama, tagline, logo, favicon.
    Route::get('settings/site', [SiteSettingController::class, 'edit'])->name('settings.site.edit');
    Route::put('settings/site', [SiteSettingController::class, 'update'])->name('settings.site.update');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');

    // User management
    Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
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

    /*
    | Data Mahasiswa — daftar, import, dan pembagian ke operator.
    |
    | Static paths come before the {student} wildcard, otherwise "import"
    | would be parsed as a student id.
    */
    Route::get('students', [StudentController::class, 'index'])->name('students.index');
    // Unsigned & Ticket: satu layar, dua proses — Generate Ticket untuk
    // seluruh daftar, dan pembagian ke operator per wilayah.
    Route::get('students/unsigned', [StudentController::class, 'unsigned'])->name('students.unsigned');
    // Alamat lama tetap hidup; tautan & bookmark yang sudah tersebar jangan mati.
    Route::get('students/unassigned', fn () => redirect()->route('students.unsigned', request()->query()))
        ->name('students.unassigned');
    Route::get('students/kecamatan', [StudentController::class, 'kecamatan'])->name('students.kecamatan');
    Route::get('students/export', [StudentController::class, 'export'])->name('students.export');

    Route::get('students/import', [StudentImportController::class, 'index'])->name('students.import.index');
    Route::post('students/import', [StudentImportController::class, 'store'])->name('students.import.store');
    Route::get('students/import/template', [StudentImportController::class, 'template'])->name('students.import.template');
    Route::get('students/import/{import}/preview', [StudentImportController::class, 'preview'])->name('students.import.preview');
    Route::post('students/import/{import}/confirm', [StudentImportController::class, 'confirm'])->name('students.import.confirm');
    Route::post('students/import/{import}/run', [StudentImportController::class, 'run'])->name('students.import.run');
    Route::get('students/import/{import}/status', [StudentImportController::class, 'status'])->name('students.import.status');
    Route::get('students/import/{import}/errors', [StudentImportController::class, 'errors'])->name('students.import.errors');
    Route::get('students/import/{import}', [StudentImportController::class, 'show'])->name('students.import.show');

    Route::post('students/assign/selected', [StudentAssignmentController::class, 'selected'])->name('students.assign.selected');
    Route::post('students/assign/region', [StudentAssignmentController::class, 'byRegion'])->name('students.assign.region');
    Route::post('students/assign/move', [StudentAssignmentController::class, 'move'])->name('students.assign.move');
    Route::post('students/assign/release', [StudentAssignmentController::class, 'release'])->name('students.assign.release');

    // Generate Ticket: satu tiket per mahasiswa, source Import Mahasiswa.
    Route::post('students/generate-tickets', [StudentTicketController::class, 'store'])
        ->name('students.tickets.generate');

    Route::get('students/{student}', [StudentController::class, 'show'])->name('students.show');
    Route::put('students/{student}', [StudentController::class, 'update'])->name('students.update');

    /*
    | Ticketing. Follow Up sengaja TIDAK punya menu sendiri — ia hidup di
    | dalam detail tiket (tickets.followUp).
    */
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/mine', [TicketController::class, 'index'])->name('tickets.mine');
    Route::get('tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/export', [TicketController::class, 'export'])->name('tickets.export');

    // Format ID Tiket — pola nomor yang dipakai setiap tiket baru.
    Route::get('tickets/settings', [TicketSettingController::class, 'edit'])->name('tickets.settings.edit');
    Route::put('tickets/settings', [TicketSettingController::class, 'update'])->name('tickets.settings.update');

    Route::get('tickets/categories', [TicketCategoryController::class, 'index'])->name('tickets.categories.index');
    Route::post('tickets/categories', [TicketCategoryController::class, 'store'])->name('tickets.categories.store');
    Route::put('tickets/categories/{category}', [TicketCategoryController::class, 'update'])->name('tickets.categories.update');
    Route::delete('tickets/categories/{category}', [TicketCategoryController::class, 'destroy'])->name('tickets.categories.destroy');

    // "Add to Ticket" dari daftar komentar Instagram.
    Route::post('interactions/{interaction}/ticket', [TicketController::class, 'fromInteraction'])
        ->name('tickets.fromInteraction');

    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::put('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
    Route::post('tickets/{ticket}/follow-up', [TicketController::class, 'followUp'])->name('tickets.followUp');
    Route::post('tickets/{ticket}/status', [TicketController::class, 'status'])->name('tickets.status');
    Route::post('tickets/{ticket}/flag', [TicketController::class, 'flag'])->name('tickets.flag');

    // TiketDetail — data mahasiswa yang ditemukan saat penanganan.
    Route::post('tickets/{ticket}/details', [TicketController::class, 'storeDetail'])->name('tickets.details.store');
    Route::delete('tickets/{ticket}/details/{detail}', [TicketController::class, 'destroyDetail'])
        ->name('tickets.details.destroy');
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
    Route::post('tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->name('tickets.reopen');

    /*
    | Task Management — modul terpisah dari Ticketing.
    */
    /*
    | Buku Tamu / Antrian — sisi petugas.
    */
    Route::get('antrian', [GuestBookController::class, 'index'])->name('guest-book.admin.index');
    Route::get('antrian/rows', [GuestBookController::class, 'rows'])->name('guest-book.admin.rows');
    Route::get('antrian/export', [GuestBookController::class, 'export'])->name('guest-book.admin.export');
    Route::post('antrian/{entry}/status', [GuestBookController::class, 'status'])->name('guest-book.admin.status');
    Route::post('antrian/{entry}/complete', [GuestBookController::class, 'complete'])->name('guest-book.admin.complete');
    Route::post('antrian/{entry}/ticket', [GuestBookController::class, 'ticket'])->name('guest-book.admin.ticket');
    Route::get('antrian/{entry}/paraf', [GuestBookController::class, 'signature'])->name('guest-book.admin.signature');

    // Jadwal kegiatan (`is_public` tasks, rendered through a whitelist — see
    // PublicTaskController). It used to be reachable without logging in; it
    // now needs a session like the rest of Task Management.
    Route::get('jadwal-kegiatan', [PublicTaskController::class, 'index'])->name('public.tasks');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    // Centang aktivitas pada papan rencana (task ke bawah, tanggal ke kanan).
    Route::post('tasks/{task}/check', [TaskCheckController::class, 'toggle'])->name('tasks.check');

    /*
    | Laporan & export.
    */
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/tickets', [ReportController::class, 'tickets'])->name('reports.tickets');
    Route::get('reports/students', [ReportController::class, 'students'])->name('reports.students');
    Route::get('reports/tasks', [ReportController::class, 'tasks'])->name('reports.tasks');
    Route::get('reports/follow-ups/export', [ReportController::class, 'followUps'])->name('reports.followUps.export');

    // Audit trail
    Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
});
