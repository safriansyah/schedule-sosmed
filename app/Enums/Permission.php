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

    // Inbox interaksi (komentar & DM lintas kanal)
    case ViewInteractions = 'interaction.view';
    case ViewAllInteractions = 'interaction.viewAll';  // lihat tugas semua petugas
    case HandleInteractions = 'interaction.handle';   // balas, ubah status, isi follow-up
    case AssignInteractions = 'interaction.assign';   // tugaskan ke petugas lain

    // Database kontak / agent
    case ViewContacts = 'contact.view';
    case ManageContacts = 'contact.manage';           // ubah nama asli, wilayah, catatan
    case ManageAgents = 'contact.agent';              // promosikan jadi agent

    // Calendar
    case ViewCalendar = 'calendar.view';
    case ManageCalendar = 'calendar.manage';   // drag & drop reschedule
    case ManageCalendarNotes = 'calendar.note'; // add notes / reminders / deadlines

    // UT Monitoring Account (bulk account datasets)
    case ViewDatasets = 'dataset.view';
    case ManageDatasets = 'dataset.manage';

    // Data mahasiswa (hasil import, dibagi ke operator)
    case ViewStudents = 'student.view';
    case ViewAllStudents = 'student.viewAll';     // lihat data operator lain
    case ManageStudents = 'student.manage';       // ubah data & catatan
    case ImportStudents = 'student.import';
    case AssignStudents = 'student.assign';       // bagikan ke operator

    // Ticketing
    case ViewTickets = 'ticket.view';
    case ViewAllTickets = 'ticket.viewAll';       // lihat tiket operator lain
    case CreateTickets = 'ticket.create';
    case HandleTickets = 'ticket.handle';         // follow up, data mahasiswa
    case EditTickets = 'ticket.edit';             // informasi, status, flag
    case AssignTickets = 'ticket.assign';
    case CloseTickets = 'ticket.close';
    case ManageTicketCategories = 'ticket.category';

    // Buku Tamu / Antrian (sisi petugas; formulir & monitornya publik)
    case ManageGuestBook = 'guestbook.manage';

    // Task management
    case ViewTasks = 'task.view';
    case ManageTasks = 'task.manage';
    case PublishTasks = 'task.publish';           // tandai task jadi publik

    // Laporan & export
    case ViewReports = 'report.view';
    case ExportData = 'report.export';

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
            self::ViewInteractions => 'Lihat inbox interaksi',
            self::ViewAllInteractions => 'Lihat tugas seluruh petugas',
            self::HandleInteractions => 'Tangani interaksi (balas & follow-up)',
            self::AssignInteractions => 'Tugaskan interaksi ke petugas',
            self::ViewContacts => 'Lihat database kontak',
            self::ManageContacts => 'Kelola & lengkapi data kontak',
            self::ManageAgents => 'Jadikan / cabut status agent',
            self::ViewCalendar => 'Lihat kalender',
            self::ManageCalendar => 'Ubah jadwal lewat kalender',
            self::ManageCalendarNotes => 'Tambah catatan & reminder di kalender',
            self::ViewDatasets => 'Lihat UT Monitoring Account',
            self::ManageDatasets => 'Unggah & kelola dataset',
            self::ViewStudents => 'Lihat data mahasiswa',
            self::ViewAllStudents => 'Lihat mahasiswa seluruh operator',
            self::ManageStudents => 'Kelola & lengkapi data mahasiswa',
            self::ImportStudents => 'Import data mahasiswa',
            self::AssignStudents => 'Bagikan mahasiswa ke operator',
            self::ViewTickets => 'Lihat tiket',
            self::ViewAllTickets => 'Lihat tiket seluruh operator',
            self::CreateTickets => 'Buat tiket',
            self::HandleTickets => 'Tangani tiket (follow up & data mahasiswa)',
            self::EditTickets => 'Ubah informasi, status & flag tiket',
            self::AssignTickets => 'Tugaskan tiket ke operator',
            self::CloseTickets => 'Tutup tiket',
            self::ManageTicketCategories => 'Kelola kategori tiket',
            self::ManageGuestBook => 'Kelola buku tamu & antrian',
            self::ViewTasks => 'Lihat task management',
            self::ManageTasks => 'Kelola task',
            self::PublishTasks => 'Publikasikan task ke halaman publik',
            self::ViewReports => 'Lihat laporan',
            self::ExportData => 'Export data',
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
