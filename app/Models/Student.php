<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\StudentCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One returning student flagged as probably not continuing.
 *
 * Holds PII (name, NIM, phone, address), so every screen that reads it is
 * permission-gated and an operator only ever sees their own assignments —
 * see scopeVisibleTo().
 */
class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nim', 'nac', 'nama', 'email', 'email_alternatif', 'no_hp', 'no_hp_raw', 'hp2', 'telp',
        'program_studi', 'fakultas', 'sipas', 'semester_terakhir', 'mri', 'mra',
        'provinsi', 'kabupaten', 'kecamatan', 'kelurahan', 'alamat', 'pokjar', 'wilayah_ujian',
        'region_id', 'segmen', 'status_dp', 'petugas_nama',
        'status_registrasi', 'status_pembayaran', 'status_billing_nac', 'status_registrasi_matkul',
        'kategori_masalah', 'kategori_masalah_raw', 'sumber_data', 'import_id',
        'assignment_status', 'assigned_to', 'assigned_by', 'assigned_at',
        'contact_id', 'catatan', 'extra',
    ];

    protected function casts(): array
    {
        return [
            'kategori_masalah' => StudentCondition::class,
            'assignment_status' => AssignmentStatus::class,
            'assigned_at' => 'datetime',
            'extra' => 'array',
        ];
    }

    /* -----------------------------------------------------------------
     | Relations
     * ----------------------------------------------------------------- */

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(StudentImport::class, 'import_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->latest();
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->where('assignment_status', AssignmentStatus::Unassigned->value)
            ->whereNull('assigned_to');
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * The student follows their ticket: whoever a student's ticket is handed
     * to becomes the student's operator, so the list never says "Belum
     * assigned" for someone an operator is already working.
     *
     * Only the assignment moves. A status already past "assigned" (Follow Up,
     * Selesai, Ditutup) is progress and is kept; only "belum assigned" is
     * raised to "assigned".
     *
     * @param  array<int, int>  $studentIds
     */
    public static function followTicketAssignee(array $studentIds, User $operator, User $actor): int
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        $updated = 0;

        foreach (array_chunk($studentIds, 1000) as $chunk) {
            $now = now();

            $updated += static::whereIn('id', $chunk)
                ->where(fn (Builder $q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $operator->id))
                ->update(['assigned_to' => $operator->id, 'assigned_by' => $actor->id, 'assigned_at' => $now]);

            static::whereIn('id', $chunk)
                ->where('assignment_status', AssignmentStatus::Unassigned->value)
                ->update(['assignment_status' => AssignmentStatus::Assigned->value]);
        }

        return $updated;
    }

    /**
     * What this user is allowed to see.
     *
     * An operator without the "see everything" permission is restricted to
     * their own assignments — enforced here, in the query, rather than by
     * hiding buttons, so a hand-typed URL cannot reach someone else's list.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(\App\Enums\Permission::ViewAllStudents)) {
            return $query;
        }

        // Theirs if the student was handed to them, OR if a ticket for the
        // student was: tickets are raised per region straight to an operator
        // now, without assigning the student first, and the operator working
        // that ticket must be able to look the student up.
        return $query->where(fn (Builder $q) => $q
            ->where('assigned_to', $user->id)
            ->orWhereExists(fn ($t) => $t->selectRaw('1')
                ->from('tickets')
                ->whereColumn('tickets.student_id', 'students.id')
                ->where('tickets.assigned_to', $user->id)
                ->whereNull('tickets.deleted_at')));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('nim', 'like', $like)
                ->orWhere('nac', 'like', $like)
                ->orWhere('nama', 'like', $like)
                ->orWhere('no_hp', 'like', $like)
                ->orWhere('no_hp_raw', 'like', $like)
                ->orWhere('hp2', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /**
     * Applies the shared filter set used by the list, the export and the
     * "assign by count" action.
     *
     * One method so all three cannot drift: the count the admin sees, the rows
     * the assign button takes and the rows the export writes are by
     * construction the same set.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->search($filters['q'] ?? null)
            // Region, widest first. Assignment is done by region now, so all
            // four levels have to narrow independently — picking a kecamatan
            // without its kabupaten still has to work.
            ->when(filled($filters['provinsi'] ?? null), fn ($q, $v) => $q->where('provinsi', $filters['provinsi']))
            ->when(filled($filters['kabupaten'] ?? null), fn ($q, $v) => $q->where('kabupaten', $filters['kabupaten']))
            ->when(filled($filters['kecamatan'] ?? null), fn ($q, $v) => $q->where('kecamatan', $filters['kecamatan']))
            ->when(filled($filters['kelurahan'] ?? null), fn ($q, $v) => $q->where('kelurahan', $filters['kelurahan']))
            ->when(filled($filters['kondisi'] ?? null), fn ($q, $v) => $q->where('kategori_masalah', $filters['kondisi']))
            // Which uploaded file a row came from. The institution imports
            // several lists — admisi belum bayar, admisi baru, non-aktif — and
            // each is a separate piece of work, so each has to be selectable
            // on its own rather than mixed into one pool of 7.400.
            ->when(filled($filters['import'] ?? null), fn ($q, $v) => $q->where('import_id', (int) $filters['import']))
            ->when(filled($filters['assignment'] ?? null), fn ($q, $v) => $q->where('assignment_status', $filters['assignment']))
            ->when(filled($filters['semester'] ?? null), fn ($q, $v) => $q->where('semester_terakhir', $filters['semester']))
            ->when(filled($filters['pokjar'] ?? null), fn ($q, $v) => $q->where('pokjar', $filters['pokjar']))
            ->when(filled($filters['segmen'] ?? null), fn ($q, $v) => $q->where('segmen', $filters['segmen']))
            ->when(filled($filters['status_dp'] ?? null), fn ($q, $v) => $q->where('status_dp', $filters['status_dp']))
            // "0" is a legitimate value meaning "unassigned", and filled('0')
            // is true, so this has to be compared as a string, not with a
            // truthiness check.
            ->when(($filters['operator'] ?? '') !== '', function (Builder $q) use ($filters) {
                return (string) $filters['operator'] === '0'
                    ? $q->whereNull('assigned_to')
                    : $q->where('assigned_to', (int) $filters['operator']);
            });
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    public function name(): string
    {
        return $this->nama ?: $this->nim;
    }

    public function initial(): string
    {
        return strtoupper(mb_substr($this->name(), 0, 1));
    }

    /** "Sungailiat, Bangka" — skips the parts the file did not have. */
    public function regionLabel(): string
    {
        return collect([$this->kelurahan, $this->kecamatan, $this->kabupaten])
            ->filter()
            ->implode(', ') ?: '—';
    }

    public function isAssigned(): bool
    {
        return $this->assigned_to !== null;
    }
}
