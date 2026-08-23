<?php

namespace App\Enums;

/**
 * Every capability in the system. Roles are granted a subset of these
 * (see RoleName::permissions()), and Gates are registered from them.
 */
enum Permission: string
{
    // Viewing
    case ViewDashboard = 'dashboard.view';
    case ViewMonitoring = 'monitoring.view';
    case ViewAnalytics = 'analytics.view';
    case ViewActivity = 'activity.view';

    // Content
    case ViewAnyContent = 'content.viewAny';
    case ViewContent = 'content.view';
    case CreateContent = 'content.create';
    case UpdateContent = 'content.update';
    case DeleteContent = 'content.delete';
    case SubmitContent = 'content.submit';
    case PublishContent = 'content.publish';

    // Approval (curator)
    case ViewApproval = 'approval.view';
    case DecideApproval = 'approval.decide';

    // Verification
    case ViewVerification = 'verification.view';
    case DecideVerification = 'verification.decide';

    // Calendar
    case ViewCalendar = 'calendar.view';
    case ManageCalendar = 'calendar.manage';   // drag & drop reschedule
    case ManageCalendarNotes = 'calendar.note'; // add notes / reminders / deadlines

    // UT Monitoring Account (bulk account datasets)
    case ViewDatasets = 'dataset.view';
    case ManageDatasets = 'dataset.manage';

    // Administration
    case ViewAccounts = 'account.view';
    case ManageAccounts = 'account.manage';
    case ViewUsers = 'user.view';
    case ManageUsers = 'user.manage';
    case ManageSettings = 'settings.manage';

    public function label(): string
    {
        return match ($this) {
            self::ViewDashboard => 'Lihat dashboard',
            self::ViewMonitoring => 'Lihat monitoring',
            self::ViewAnalytics => 'Lihat analytics',
            self::ViewActivity => 'Lihat log aktivitas',
            self::ViewAnyContent => 'Lihat daftar konten',
            self::ViewContent => 'Lihat detail konten',
            self::CreateContent => 'Buat konten',
            self::UpdateContent => 'Ubah konten',
            self::DeleteContent => 'Hapus konten',
            self::SubmitContent => 'Kirim konten untuk approval',
            self::PublishContent => 'Publikasikan konten',
            self::ViewApproval => 'Lihat antrean approval',
            self::DecideApproval => 'Approve / tolak / minta revisi',
            self::ViewVerification => 'Lihat antrean verifikasi',
            self::DecideVerification => 'Verifikasi konten',
            self::ViewCalendar => 'Lihat kalender',
            self::ManageCalendar => 'Ubah jadwal lewat kalender',
            self::ManageCalendarNotes => 'Tambah catatan & reminder di kalender',
            self::ViewDatasets => 'Lihat UT Monitoring Account',
            self::ManageDatasets => 'Unggah & kelola dataset',
            self::ViewAccounts => 'Lihat akun sosmed',
            self::ManageAccounts => 'Kelola akun sosmed',
            self::ViewUsers => 'Lihat pengguna',
            self::ManageUsers => 'Kelola pengguna',
            self::ManageSettings => 'Kelola pengaturan',
        };
    }

    /** Grouping used by the permission matrix UI. */
    public function group(): string
    {
        return str($this->value)->before('.')->headline()->toString();
    }
}
