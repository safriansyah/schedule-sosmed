<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case Director = 'director';
    case Creative = 'creative';
    case Curator = 'curator';
    case Verifier = 'verifier';

    /* CRM roles — these handle people, not content. */
    case Manager = 'manager';
    case Pic = 'pic';
    case Operator = 'operator';
    case FollowUp = 'follow_up';

    /* Front desk — the walk-in queue (Buku Tamu / Antrian). */
    case Frontdesk = 'frontdesk';

    /**
     * The name plus what the role is FOR, in brackets.
     *
     * "Manager" and "PIC" say nothing about which half of the system someone
     * works in — this app runs two very different workflows (content
     * production and student handling) and the same word means different jobs
     * in each. The bracket is what makes a user list readable at a glance.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin (Semua Akses)',
            self::Director => 'Direktur (Pemantauan)',
            self::Creative => 'Tim Creative (Konten)',
            self::Curator => 'Approval / Curator (Konten)',
            self::Verifier => 'Tim Verifikasi (Konten)',
            self::Manager => 'Manager (Penanganan & Pembagian)',
            self::Pic => 'PIC (Penanganan)',
            self::Operator => 'Operator (Penanganan & Data)',
            self::FollowUp => 'Operator Follow Up (Tiket)',
            self::Frontdesk => 'Operator Frontdesk (Buku Tamu)',
        };
    }

    /** The name on its own, for places where the bracket would not fit. */
    public function shortLabel(): string
    {
        return trim(str($this->label())->before('(')->toString());
    }

    /**
     * Which side of the app this role lives on — used to group the user list.
     */
    public function area(): string
    {
        return match ($this) {
            self::Creative, self::Curator, self::Verifier => 'Konten',
            self::Manager, self::Pic, self::Operator, self::FollowUp, self::Frontdesk => 'Penanganan',
            default => 'Umum',
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
            self::Manager => 'Membagi tugas, memantau SLA, dan mengeskalasi interaksi mendesak.',
            self::Pic => 'Menangani dan membalas interaksi pada kanal yang menjadi tanggung jawabnya.',
            self::Operator => 'Menangani interaksi, melengkapi data kontak, dan mengangkat agent baru.',
            self::FollowUp => 'Menindaklanjuti dan menutup tiket yang ditugaskan kepadanya, serta melihat data mahasiswanya. '
                .'Tidak bisa membuat, membagikan, atau melihat tiket orang lain.',
            self::Frontdesk => 'Mengelola Buku Tamu / Antrian: memanggil, melayani, menyelesaikan, dan meneruskan tamu menjadi tiket.',
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
            self::Manager => 'users',
            self::Pic => 'headset',
            self::Operator => 'user-plus',
            self::FollowUp => 'phone',
            self::Frontdesk => 'id-card',
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
                Permission::ViewInteractions,
                Permission::ViewAllInteractions,
                Permission::ViewContacts,
                Permission::ViewStudents,
                Permission::ViewAllStudents,
                Permission::ViewLeads,
                Permission::ViewTickets,
                Permission::ViewAllTickets,
                Permission::ViewTasks,
                Permission::ViewReports,
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

            // Manager owns the queue but does not edit contact records: they
            // distribute and escalate, operators do the data work.
            self::Manager => [
                ...$read,
                Permission::ViewActivity,
                Permission::ViewInteractions,
                Permission::ViewAllInteractions,
                Permission::HandleInteractions,
                Permission::AssignInteractions,
                Permission::ViewContacts,
                Permission::ViewDatasets,

                // The "Admin" of the CRM brief: imports the list, decides who
                // gets what, runs ticketing and plans the team's work.
                Permission::ViewStudents,
                Permission::ViewAllStudents,
                Permission::ManageStudents,
                Permission::ImportStudents,
                Permission::AssignStudents,
                Permission::ViewLeads,
                Permission::ImportLeads,
                Permission::ViewTickets,
                Permission::ViewAllTickets,
                Permission::CreateTickets,
                Permission::HandleTickets,
                Permission::EditTickets,
                Permission::ManageGuestBook,
                Permission::AssignTickets,
                Permission::CloseTickets,
                Permission::ManageTicketCategories,
                Permission::ViewTasks,
                Permission::ManageTasks,
                Permission::PublishTasks,
                Permission::ViewReports,
                Permission::ExportData,
            ],

            // PIC answers; they may not reassign work or promote agents.
            self::Pic => [
                ...$read,
                Permission::ViewInteractions,
                Permission::HandleInteractions,
                Permission::ViewContacts,

                // No ViewAllStudents / ViewAllTickets: the scopes then restrict
                // every list to this user's own assignments.
                Permission::ViewStudents,
                Permission::ViewTickets,
                Permission::CreateTickets,
                Permission::HandleTickets,
                Permission::EditTickets,
                // Whoever works a ticket finishes it. Closing demands a
                // resolution note and is the natural end of the follow-up the
                // handler is already doing, so withholding it left a PIC able
                // to do every step except the last — and left Operator, the
                // field role, with MORE authority on the same flow. The
                // restriction that is deliberate is reassignment, not closure.
                Permission::CloseTickets,
                Permission::ViewTasks,
            ],

            /*
             | The narrowest role in the system: follow up the tickets handed to
             | them, and nothing else.
             |
             | It deliberately does NOT get the $read baseline above. Every other
             | role does, which quietly grants content, calendar, analytics and
             | monitoring — reasonable for a team member, wrong for someone whose
             | whole job is phoning the students on their own list. Leaving the
             | baseline out is the point of this role, not an oversight.
             |
             | What each omission buys:
             |   no ViewAllTickets  -> the list and guardVisibility() narrow to
             |                         tickets assigned to (or raised by) them
             |   no CreateTickets   -> they work the queue, they do not open it
             |   no AssignTickets   -> they cannot hand work to anyone else
             |   no EditTickets     -> the ticket's information, status and flag
             |                         are read-only to them; they add follow-ups
             |                         and student data, nothing more
             |
             | CloseTickets IS granted: whoever finishes the follow-up closes
             | the ticket, with the resolution note the close form demands. It
             | only reaches their own tickets — close() and reopen() go through
             | guardVisibility(), which without ViewAllTickets admits nothing
             | assigned to (or raised by) someone else.
             */
            self::FollowUp => [
                Permission::ViewDashboard,
                Permission::ViewTickets,
                Permission::HandleTickets,
                Permission::CloseTickets,
                // Daftar Mahasiswa, read-only. Without ViewAllStudents the
                // list and Student::visibleTo() show only the students handed
                // to them — the ones behind their tickets.
                Permission::ViewStudents,
            ],

            /*
             | The front desk: runs the walk-in queue (Buku Tamu / Antrian) —
             | call, serve, finish, skip — and hands a visitor on as a ticket
             | when the case needs follow-up. Together with the Manager, the
             | only role that manages the queue.
             |
             |   CreateTickets + AssignTickets -> "Add Ticket" with "Tugaskan ke"
             |   ViewTickets (no ViewAllTickets) -> only the tickets they raised,
             |                         so the link after Add Ticket opens
             |
             | No HandleTickets: the follow-up itself is the operator's job.
             */
            self::Frontdesk => [
                Permission::ViewDashboard,
                Permission::ManageGuestBook,
                Permission::ViewTickets,
                Permission::CreateTickets,
                Permission::AssignTickets,
            ],

            // Operator is the only non-admin role that may enrich a contact
            // record and press "Jadikan Agent".
            self::Operator => [
                ...$read,
                Permission::ViewInteractions,
                Permission::HandleInteractions,
                Permission::ViewContacts,
                Permission::ManageContacts,
                Permission::ManageAgents,

                // Sees only what was assigned to them — the "viewAll" pair is
                // deliberately absent, and the query scopes enforce it rather
                // than the UI hiding buttons.
                Permission::ViewStudents,
                // Daftar Mahasiswa is read-only for operators: they check the
                // records they were given, the admin keeps them correct.
                // (No ManageStudents.)
                Permission::ViewTickets,
                Permission::CreateTickets,
                Permission::HandleTickets,
                Permission::EditTickets,
                Permission::CloseTickets,
                Permission::ViewTasks,
                Permission::ExportData,
            ],
        };
    }

    /** Roles that may only read — used to hide write actions in the UI. */
    public function isReadOnly(): bool
    {
        return $this === self::Director;
    }
}
