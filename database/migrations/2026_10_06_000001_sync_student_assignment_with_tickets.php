<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix, no schema change.
 *
 * For a while "Buat Ticket per Wilayah" handed tickets straight to an
 * operator without touching the student, so /students showed those students
 * as "Belum assigned" while an operator was already working them. From now on
 * the student follows the ticket (Student::followTicketAssignee); this brings
 * the rows written in between into line.
 *
 * Only students with no operator at all are touched: each takes the operator
 * of their most recent open ticket (or, failing that, most recent ticket).
 * Anyone already assigned is left exactly as they are. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('students')
            ->whereNull('assigned_to')
            ->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('tickets')
                ->whereColumn('tickets.student_id', 'students.id')
                ->whereNotNull('tickets.assigned_to')
                ->whereNull('tickets.deleted_at'))
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($students) use ($now) {
                foreach ($students as $student) {
                    $ticket = DB::table('tickets')
                        ->where('student_id', $student->id)
                        ->whereNotNull('assigned_to')
                        ->whereNull('deleted_at')
                        // Open first, then the newest.
                        ->orderByRaw("CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END")
                        ->orderByDesc('id')
                        ->first(['assigned_to', 'created_by']);

                    if (! $ticket) {
                        continue;
                    }

                    DB::table('students')->where('id', $student->id)->update([
                        'assigned_to' => $ticket->assigned_to,
                        'assigned_by' => $ticket->created_by,
                        'assigned_at' => $now,
                        'assignment_status' => DB::raw("CASE WHEN assignment_status = 'belum_assigned' THEN 'assigned' ELSE assignment_status END"),
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // A data correction; there is nothing meaningful to undo.
    }
};
