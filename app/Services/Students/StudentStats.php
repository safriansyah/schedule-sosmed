<?php

namespace App\Services\Students;

use App\Enums\AssignmentStatus;
use App\Enums\Permission;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The counters shown on the student screens, the dashboard and the reports.
 *
 * Centralised the way InboxSummary already is for the interaction inbox: three
 * screens showing three different totals for "belum assigned" would destroy
 * trust in all of them, so they all read from here.
 *
 * Every method is scoped to the viewer — an operator's "total" is their own
 * caseload, not the institution's.
 */
class StudentStats
{
    /**
     * How long a filter dropdown's contents may be stale.
     *
     * These four DISTINCT scans over 7.391 rows cost ~45 ms on every single
     * page load, to produce lists that only change when someone imports a file
     * or edits a student — both of which clear the cache explicitly, so the
     * TTL is only a backstop for changes made outside the app.
     */
    private const OPTIONS_TTL = 3600;

    /** Bumped to retire every cached dropdown at once — see forgetOptions(). */
    private const VERSION_KEY = 'students:options:version';

    /**
     * The region hierarchy, widest first.
     *
     * Pokjar sits at the end because it is the level the institution
     * actually organises by — the export has no province and no kelurahan,
     * so on today's data those two are empty while this one is not. Each
     * level narrows the ones after it.
     */
    public const REGION_LEVELS = ['provinsi', 'kabupaten', 'kecamatan', 'kelurahan', 'pokjar'];

    /** @return array<string, string> */
    public static function regionLabels(): array
    {
        return [
            'provinsi' => 'Provinsi',
            'kabupaten' => 'Kabupaten / Kota',
            'kecamatan' => 'Kecamatan',
            'kelurahan' => 'Kelurahan / Desa',
            'pokjar' => 'Pokjar / SALUT',
        ];
    }

    /**
     * Headline numbers, one grouped query rather than five counts.
     *
     * @return array<string, int>
     */
    public function summary(?User $user = null): array
    {
        $byStatus = $this->scoped($user)
            ->selectRaw('assignment_status, COUNT(*) as total')
            ->groupBy('assignment_status')
            ->pluck('total', 'assignment_status');

        $get = fn (AssignmentStatus $s) => (int) ($byStatus[$s->value] ?? 0);

        $unassigned = $get(AssignmentStatus::Unassigned);
        $total = (int) $byStatus->sum();

        return [
            'total' => $total,
            'unassigned' => $unassigned,
            // "Assigned" in the brief's dashboard means "handed out", i.e.
            // everything that is not still in the pool — not just the rows
            // sitting in the Assigned status.
            'assigned' => $total - $unassigned,
            'follow_up' => $get(AssignmentStatus::FollowUp),
            'resolved' => $get(AssignmentStatus::Resolved),
            'closed' => $get(AssignmentStatus::Closed),
        ];
    }

    /**
     * Per-operator breakdown for the dashboard table.
     *
     * @return Collection<int, object>
     */
    public function workload(): Collection
    {
        return Student::query()
            ->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'students.assigned_to')
            ->groupBy('students.assigned_to', 'users.name')
            ->selectRaw('students.assigned_to as user_id, users.name as name, COUNT(*) as total')
            ->selectRaw('SUM(students.assignment_status = ?) as assigned', [AssignmentStatus::Assigned->value])
            ->selectRaw('SUM(students.assignment_status = ?) as follow_up', [AssignmentStatus::FollowUp->value])
            ->selectRaw('SUM(students.assignment_status = ?) as resolved', [AssignmentStatus::Resolved->value])
            ->selectRaw('SUM(students.assignment_status = ?) as closed', [AssignmentStatus::Closed->value])
            ->orderByDesc('total')
            ->get();
    }

    /**
     * The kabupaten actually present in the data.
     *
     * Read from the students table rather than from a hard-coded list of the
     * seven Bangka Belitung regencies: the real file may reach other provinces,
     * and a filter that cannot select a value that exists is a bug.
     *
     * @return array<int, string>
     */
    public function kabupatenOptions(?User $user = null): array
    {
        return $this->distinctValues('kabupaten', $user);
    }

    /**
     * Kecamatan within one kabupaten — or all of them when none is chosen.
     *
     * @return array<int, string>
     */
    public function kecamatanOptions(?User $user = null, ?string $kabupaten = null): array
    {
        return $this->regionOptions('kecamatan', $user, filled($kabupaten) ? ['kabupaten' => $kabupaten] : []);
    }

    /**
     * Every region level at once, each narrowed by the levels above it.
     *
     * Feeds the cascading Provinsi → Kabupaten → Kecamatan → Kelurahan →
     * Pokjar selectors on the hand-out screen. A level whose list comes back
     * empty is shown disabled rather than hidden: "this file carries no
     * kelurahan" is information, and a selector that silently vanishes looks
     * like a bug.
     *
     * @param  array<string, string|null>  $chosen  level => value already picked
     * @return array<string, array<int, string>>
     */
    public function regionTree(?User $user, array $chosen): array
    {
        $options = [];
        $parents = [];

        foreach (self::REGION_LEVELS as $level) {
            $options[$level] = $this->regionOptions($level, $user, $parents);

            if (filled($chosen[$level] ?? null)) {
                $parents[$level] = $chosen[$level];
            }
        }

        return $options;
    }

    /**
     * Distinct values of one region level, within the levels already chosen.
     *
     * @param  array<string, string>  $parents
     * @return array<int, string>
     */
    public function regionOptions(string $level, ?User $user = null, array $parents = []): array
    {
        if (! in_array($level, self::REGION_LEVELS, true)) {
            return [];
        }

        // Only levels ABOVE this one narrow it. Filtering kabupaten by a
        // chosen kecamatan would empty the list the moment someone picks a
        // child, which is the classic broken cascade.
        $above = [];

        foreach (self::REGION_LEVELS as $candidate) {
            if ($candidate === $level) {
                break;
            }

            if (filled($parents[$candidate] ?? null)) {
                $above[$candidate] = (string) $parents[$candidate];
            }
        }

        return $this->distinctValues($level, $user, $above);
    }

    /**
     * Pokjar / SALUT actually present in the data.
     *
     * @return array<int, string>
     */
    public function pokjarOptions(?User $user = null): array
    {
        return $this->distinctValues('pokjar', $user);
    }

    /** @return array<int, string> */
    public function segmenOptions(?User $user = null): array
    {
        return $this->distinctValues('segmen', $user);
    }

    /**
     * Distinct non-empty values of one column, for a filter dropdown.
     *
     * @return array<int, string>
     */
    private function distinctValues(string $column, ?User $user = null, array $within = []): array
    {
        $within = array_filter($within, fn ($v) => filled($v));
        ksort($within);

        $key = sprintf(
            'students:options:v%d:%s:%s:%s',
            $this->optionsVersion(),
            $column,
            $user?->hasPermission(Permission::ViewAllStudents) === false ? 'u'.$user->id : 'all',
            $within === [] ? '-' : md5(json_encode($within)),
        );

        return Cache::remember($key, self::OPTIONS_TTL, function () use ($column, $user, $within) {
            $query = $this->scoped($user)
                ->whereNotNull($column)
                ->where($column, '!=', '');

            foreach ($within as $level => $value) {
                $query->where($level, $value);
            }

            return $query->distinct()->orderBy($column)->pluck($column)->all();
        });
    }

    /**
     * Drop the cached dropdowns.
     *
     * Called after an import, after a student edit and after a bulk ticket
     * generation, so a newly-appearing kabupaten is selectable immediately
     * rather than in an hour.
     *
     * Done by bumping a version number that every key carries, not by
     * forgetting keys one at a time. The cascading region selectors key on the
     * levels already chosen, so the number of live keys is the number of
     * region combinations anyone has looked at — not enumerable, and growing.
     * Listing them was already only approximately right; now it would be
     * plainly wrong, leaving an admin filtering on a kabupaten that no longer
     * exists.
     */
    public function forgetOptions(): void
    {
        Cache::forever(self::VERSION_KEY, $this->optionsVersion() + 1);
    }

    private function optionsVersion(): int
    {
        return (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
    }

    /**
     * How the list breaks down by reason, for the reports page.
     *
     * @return array<string, int>
     */
    public function byCondition(?User $user = null): array
    {
        return $this->scoped($user)
            ->selectRaw('kategori_masalah, COUNT(*) as total')
            ->groupBy('kategori_masalah')
            ->pluck('total', 'kategori_masalah')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Regional breakdown, biggest first.
     *
     * @return Collection<int, object>
     */
    public function byRegion(?User $user = null, int $limit = 15): Collection
    {
        return $this->scoped($user)
            ->whereNotNull('kabupaten')
            ->groupBy('kabupaten')
            ->selectRaw('kabupaten, COUNT(*) as total')
            ->selectRaw('SUM(assigned_to IS NULL) as unassigned')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Base query, narrowed to what this user may count.
     *
     * A null user means "system-wide" and is used by the scheduled reports;
     * anything reached from a request passes the real user.
     */
    private function scoped(?User $user)
    {
        $query = Student::query();

        if ($user === null || $user->hasPermission(Permission::ViewAllStudents)) {
            return $query;
        }

        return $query->where('assigned_to', $user->id);
    }
}
