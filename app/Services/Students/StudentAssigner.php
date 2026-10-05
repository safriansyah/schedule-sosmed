<?php

namespace App\Services\Students;

use App\Enums\AssignmentStatus;
use App\Enums\TicketStatus;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Students\StudentStats;
use Illuminate\Support\Facades\DB;

/**
 * Hands imported students out to operators.
 *
 * The invariant this class exists to hold: a student is assigned to exactly
 * one operator, once. Two admins distributing the same filtered list at the
 * same moment must not both give row #412 away.
 *
 * That is enforced in the database, not in PHP. Every write is an UPDATE whose
 * WHERE clause still contains `assigned_to IS NULL`, run inside a transaction
 * over rows selected FOR UPDATE. The second admin's statement then matches
 * nothing for the rows the first one took, and the count they are shown is the
 * number of rows that actually changed — never the number requested.
 */
class StudentAssigner
{
    public function __construct(private readonly ActivityLogger $log) {}

    /**
     * Assign specific students, chosen by tick-box.
     *
     * @param  array<int, int>  $studentIds
     * @param  bool  $reassign  allow taking students off their current operator
     * @return array{assigned: int, skipped: int}
     */
    public function assignSelected(array $studentIds, User $operator, User $actor, bool $reassign = false): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        if ($studentIds === []) {
            return ['assigned' => 0, 'skipped' => 0];
        }

        $assigned = DB::transaction(function () use ($studentIds, $operator, $actor, $reassign) {
            $query = Student::whereIn('id', $studentIds);

            // Without --reassign a student already held by someone else is
            // left alone, so a careless bulk action cannot quietly empty
            // another operator's queue.
            if (! $reassign) {
                $query->whereNull('assigned_to');
            }

            $ids = $query->lockForUpdate()->pluck('id')->all();

            return $ids === [] ? 0 : $this->apply($ids, $operator, $actor);
        });

        $this->logHandout($assigned, $operator, $actor, 'terpilih');

        return ['assigned' => $assigned, 'skipped' => count($studentIds) - $assigned];
    }

    /**
     * Hand an operator every unassigned student in one region.
     *
     * This replaced "assign the first 100". A headcount cuts the list at an
     * arbitrary row: two students in the same village end up with different
     * operators, and neither operator can plan a trip or recognise a name. A
     * region is the unit the work is actually done in, so it is the unit the
     * work is handed out in.
     *
     * There is deliberately no limit. The admin picks how far down the
     * hierarchy to go — a whole kabupaten or one kelurahan — and that choice
     * IS the size of the batch.
     *
     * @param  array<string, string|null>  $region  level => value, widest first
     * @return array{assigned: int, matched: int}
     */
    public function assignByRegion(array $region, User $operator, User $actor): array
    {
        $region = array_filter(
            array_intersect_key($region, array_flip(StudentStats::REGION_LEVELS)),
            fn ($value) => filled($value),
        );

        if ($region === []) {
            // Without this an empty form would hand the operator all 7.400
            // students in one click, with no warning and no undo.
            return ['assigned' => 0, 'matched' => 0];
        }

        $matched = $this->inRegion($region)->count();

        $assigned = DB::transaction(function () use ($region, $operator, $actor) {
            $ids = $this->inRegion($region)
                ->unassigned()
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            return $ids === [] ? 0 : $this->apply($ids, $operator, $actor);
        });

        if ($assigned > 0) {
            $this->log->log(
                'student.assigned',
                sprintf(
                    'Assign %d mahasiswa wilayah %s ke %s',
                    $assigned,
                    implode(' › ', $region),
                    $operator->name,
                ),
                null,
                [
                    'count' => $assigned,
                    'operator_id' => $operator->id,
                    'operator' => $operator->name,
                    'region' => $region,
                ],
            );
        }

        return ['assigned' => $assigned, 'matched' => $matched];
    }

    /** @param  array<string, string>  $region */
    private function inRegion(array $region)
    {
        $query = Student::query();

        foreach ($region as $level => $value) {
            $query->where($level, $value);
        }

        return $query;
    }

    /**
     * "Pindah Operator": everything one operator holds in a region goes to
     * another — the fix for a region handed to the wrong person.
     *
     * Students and their OPEN tickets move together; closed tickets stay with
     * whoever closed them, since that work is done. Each ticket's assignment
     * history records the move.
     *
     * @param  array<string, string|null>  $region
     * @return array{students: int, tickets: int}
     */
    public function moveRegion(array $region, User $from, User $to, User $actor): array
    {
        $region = array_filter(
            array_intersect_key($region, array_flip(StudentStats::REGION_LEVELS)),
            fn ($value) => filled($value),
        );

        // Same guard as assignByRegion(): no region would mean "everything
        // this operator holds", which is a different, much bigger action.
        if ($region === [] || $from->is($to)) {
            return ['students' => 0, 'tickets' => 0];
        }

        $result = DB::transaction(function () use ($region, $from, $to, $actor) {
            $ids = $this->inRegion($region)
                ->where('assigned_to', $from->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return ['students' => 0, 'tickets' => 0];
            }

            $students = Student::whereIn('id', $ids)->update([
                'assignment_status' => AssignmentStatus::Assigned->value,
                'assigned_to' => $to->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            $tickets = $this->handOverTickets($ids, $to, $actor, "Pindah operator dari {$from->name}");

            return ['students' => $students, 'tickets' => $tickets];
        });

        if ($result['students'] > 0) {
            $this->log->log(
                'student.moved',
                sprintf(
                    'Pindah %d mahasiswa (%d tiket) wilayah %s dari %s ke %s',
                    $result['students'],
                    $result['tickets'],
                    implode(' › ', $region),
                    $from->name,
                    $to->name,
                ),
                null,
                ['from' => $from->id, 'to' => $to->id, 'region' => $region] + $result,
            );
        }

        return $result;
    }

    /**
     * Take students back off an operator, returning them to the pool.
     *
     * @param  array<int, int>  $studentIds
     */
    public function unassign(array $studentIds, User $actor): int
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        if ($studentIds === []) {
            return 0;
        }

        $count = Student::whereIn('id', $studentIds)
            ->whereNotNull('assigned_to')
            ->update([
                'assignment_status' => AssignmentStatus::Unassigned->value,
                'assigned_to' => null,
                'assigned_by' => null,
                'assigned_at' => null,
            ]);

        if ($count > 0) {
            $this->log->log(
                'student.unassigned',
                "Menarik kembali {$count} mahasiswa ke daftar belum assigned",
                null,
                ['count' => $count, 'students' => array_slice($studentIds, 0, 50)],
            );
        }

        return $count;
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * The single write. Called only from inside a transaction, on ids that
     * were just locked.
     *
     * @param  array<int, int>  $ids
     */
    private function apply(array $ids, User $operator, User $actor): int
    {
        $count = Student::whereIn('id', $ids)->update([
            'assignment_status' => AssignmentStatus::Assigned->value,
            'assigned_to' => $operator->id,
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
        ]);

        $this->handOverTickets($ids, $operator, $actor);

        return $count;
    }

    /**
     * The student's open tickets go with them: whoever is given a student is
     * the one following up their tickets, exactly as if each ticket had been
     * assigned from its own page. Each hand-over is written to the ticket's
     * assignment history, so the change is traceable there too.
     *
     * @param  array<int, int>  $studentIds
     */
    private function handOverTickets(array $studentIds, User $operator, User $actor, string $note = 'Ikut penugasan mahasiswa'): int
    {
        $moved = 0;

        foreach (array_chunk($studentIds, 1000) as $chunk) {
            $tickets = Ticket::query()
                ->open()
                ->whereIn('student_id', $chunk)
                ->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $operator->id))
                ->get(['id', 'assigned_to', 'status']);

            if ($tickets->isEmpty()) {
                continue;
            }

            $now = now();
            $ids = $tickets->pluck('id')->all();

            $moved += Ticket::whereIn('id', $ids)->update(['assigned_to' => $operator->id, 'assigned_at' => $now]);

            // Open → Assigned, the same step TicketService::assign() takes.
            Ticket::whereIn('id', $ids)
                ->where('status', TicketStatus::Open->value)
                ->update(['status' => TicketStatus::Assigned->value]);

            TicketAssignment::insert($tickets->map(fn (Ticket $t) => [
                'ticket_id' => $t->id,
                'from_user_id' => $t->assigned_to,
                'to_user_id' => $operator->id,
                'assigned_by' => $actor->id,
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        return $moved;
    }

    private function logHandout(int $count, User $operator, User $actor, string $how): void
    {
        if ($count < 1) {
            return;
        }

        $this->log->log(
            'student.assigned',
            "Assign {$count} mahasiswa ({$how}) ke {$operator->name}",
            null,
            ['count' => $count, 'operator_id' => $operator->id, 'operator' => $operator->name],
        );
    }
}
