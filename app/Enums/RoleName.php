<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case Director = 'director';
    case Creative = 'creative';
    case Curator = 'curator';
    case Verifier = 'verifier';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Director => 'Direktur',
            self::Creative => 'Tim Creative',
            self::Curator => 'Approval / Curator',
            self::Verifier => 'Tim Verifikasi',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Akses penuh ke seluruh sistem.',
            self::Director => 'Monitoring menyeluruh — hanya melihat, tidak mengubah.',
            self::Creative => 'Membuat draft dan mengunggah media untuk ditinjau.',
            self::Curator => 'Meninjau konten: approve, tolak, minta revisi, dan menentukan jadwal terbit.',
            self::Verifier => 'Pengecekan akhir sebelum konten siap terbit.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SuperAdmin => 'shield',
            self::Director => 'eye',
            self::Creative => 'image',
            self::Curator => 'check-circle',
            self::Verifier => 'badge-check',
        };
    }

    /**
     * Default capability matrix. Super Admin is handled by a Gate::before
     * bypass, so it is granted everything here for completeness.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        // Baseline every role shares. UT Monitoring Account (datasets) is NOT
        // here — it is limited to admin & director. Calendar notes are granted
        // to every role, so anyone can flag deadlines/reminders.
        $read = [
            Permission::ViewDashboard,
            Permission::ViewAnyContent,
            Permission::ViewContent,
            Permission::ViewCalendar,
            Permission::ViewAnalytics,
            Permission::ViewMonitoring,
            Permission::ManageCalendarNotes,
        ];

        return match ($this) {
            self::SuperAdmin => Permission::cases(),

            // The director is read-only on content, but may annotate the
            // calendar and is one of the two roles that see UT Monitoring.
            self::Director => [
                ...$read,
                Permission::ViewActivity,
                Permission::ViewApproval,
                Permission::ViewVerification,
                Permission::ViewAccounts,
                Permission::ViewUsers,
                Permission::ViewDatasets,
            ],

            self::Creative => [
                ...$read,
                Permission::CreateContent,
                Permission::UpdateContent,
                Permission::DeleteContent,
                Permission::SubmitContent,
                Permission::ManageCalendar,
            ],

            self::Curator => [
                ...$read,
                Permission::ViewApproval,
                Permission::DecideApproval,
            ],

            self::Verifier => [
                ...$read,
                Permission::ViewVerification,
                Permission::DecideVerification,
            ],
        };
    }

    /** Roles that may only read — used to hide write actions in the UI. */
    public function isReadOnly(): bool
    {
        return $this === self::Director;
    }
}
