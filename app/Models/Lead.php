<?php

namespace App\Models;

use App\Enums\LeadSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A prospective student from a marketing list. See the leads migration. */
class Lead extends Model
{
    protected $fillable = ['nama', 'no_hp', 'kota', 'asal_sekolah', 'sumber', 'extra', 'import_id'];

    protected function casts(): array
    {
        return [
            'sumber' => LeadSource::class,
            'extra' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class, 'import_id');
    }

    /** Name, phone (as typed: 08… or 62…), kota or school. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $phone = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

        return $query->where(function (Builder $q) use ($term, $phone) {
            $q->where('nama', 'like', "%{$term}%")
                ->orWhere('kota', 'like', "%{$term}%")
                ->orWhere('asal_sekolah', 'like', "%{$term}%");

            if (strlen($phone) >= 4) {
                $q->orWhere('no_hp', 'like', "%{$phone}%");
            }
        });
    }
}
