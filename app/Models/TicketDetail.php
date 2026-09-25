<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TiketDetail — what we established about a person while handling a ticket.
 *
 * Distinct from Student on purpose: a Student row is the institution's
 * imported record, this is the operator's finding on one case. They are linked
 * by `student_id` when the NIM turns out to be in the import, and the detail
 * stands alone when it is not.
 */
class TicketDetail extends Model
{
    protected $fillable = [
        'ticket_id', 'nim', 'student_id', 'nama', 'fakultas', 'prodi',
        'provinsi', 'kabupaten', 'kecamatan', 'kelurahan', 'region_id',
        'no_hp', 'email', 'catatan', 'extra', 'created_by',
    ];

    protected function casts(): array
    {
        return ['extra' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Fills the blanks from the imported student record, without overwriting
     * anything the operator actually typed.
     *
     * The operator is talking to the person; the spreadsheet is months old. So
     * what they entered wins, and this only supplies what they left empty.
     */
    public function fillFromStudent(Student $student): self
    {
        $this->student_id ??= $student->id;

        foreach ([
            'nama' => $student->nama,
            'fakultas' => $student->fakultas,
            'prodi' => $student->program_studi,
            'provinsi' => $student->provinsi,
            'kabupaten' => $student->kabupaten,
            'kecamatan' => $student->kecamatan,
            'kelurahan' => $student->kelurahan,
            'no_hp' => $student->no_hp_raw ?: $student->no_hp,
            'email' => $student->email,
        ] as $column => $value) {
            if (blank($this->{$column}) && filled($value)) {
                $this->{$column} = $value;
            }
        }

        return $this;
    }

    /**
     * What to show for one column: this row's own value, falling back to the
     * linked import record.
     *
     * Resolved when READ, not copied when written. Tickets generated in bulk
     * from the student list carry only the NIM and the link — copying ten
     * columns onto 7.400 rows would be a lot of duplicated data that goes
     * stale the moment somebody corrects the student record. This way the
     * panel showed "Tanpa nama / — / —" for exactly those tickets, which is
     * the bug this fixes, and a corrected student shows through immediately.
     *
     * The operator's own entry still wins wherever they made one: they spoke
     * to the person, the spreadsheet is months old.
     */
    public function shown(string $column): ?string
    {
        if (filled($this->{$column})) {
            return $this->{$column};
        }

        $student = $this->student;

        if ($student === null) {
            return null;
        }

        return match ($column) {
            'nama' => $student->nama,
            'fakultas' => $student->fakultas,
            // The two tables named this column differently.
            'prodi' => $student->program_studi,
            'provinsi' => $student->provinsi,
            'kabupaten' => $student->kabupaten,
            'kecamatan' => $student->kecamatan,
            'kelurahan' => $student->kelurahan,
            'no_hp' => $student->no_hp_raw ?: $student->no_hp,
            'email' => $student->email,
            'nac' => $student->nac,
            default => null,
        };
    }

    /** The name to head the panel with, never blank when a link exists. */
    public function displayName(): string
    {
        return $this->shown('nama') ?: 'Tanpa nama';
    }

    /** "Kelurahan, Kecamatan, Kabupaten, Provinsi" — skipping what is missing. */
    public function regionLabel(): string
    {
        return collect([
            $this->shown('kelurahan'),
            $this->shown('kecamatan'),
            $this->shown('kabupaten'),
            $this->shown('provinsi'),
        ])->filter()->implode(', ') ?: '—';
    }

    public function academicLabel(): string
    {
        return collect([$this->shown('prodi'), $this->shown('fakultas')])
            ->filter()
            ->implode(' · ') ?: '—';
    }
}
