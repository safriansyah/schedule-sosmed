<?php

namespace App\Models;

use App\Enums\RegionLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One administrative area. See the migration for the four-level shape. */
class Region extends Model
{
    protected $fillable = ['parent_id', 'code', 'level', 'name', 'full_path'];

    protected function casts(): array
    {
        return ['level' => RegionLevel::class];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function scopeLevel(Builder $query, RegionLevel $level): Builder
    {
        return $query->where('level', $level->value);
    }

    /**
     * The chain up to the province, outermost first.
     *
     * @return array<int, self>
     */
    public function ancestors(): array
    {
        $chain = [];

        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            array_unshift($chain, $node);
        }

        return $chain;
    }

    /** "Desa X, Kec. Y, Kab. Z, Prov. W" — falls back to walking the tree. */
    public function label(): string
    {
        if (filled($this->full_path)) {
            return $this->full_path;
        }

        return collect([$this, ...array_reverse($this->ancestors())])
            ->map(fn (self $r) => $r->name)
            ->implode(', ');
    }
}
