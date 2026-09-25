<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Application settings, one row per option.
 *
 * The table shipped with the project but was never used. It is the right home
 * for the ticket numbering format: config files are deployed, and this is
 * something an admin changes from a screen.
 *
 * Reads are cached forever and busted on write, because settings are read on
 * nearly every ticket creation and changed a handful of times a year.
 */
class Setting extends Model
{
    protected $fillable = ['option_name', 'option_value'];

    private const CACHE_PREFIX = 'setting:';

    public static function get(string $name, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            self::CACHE_PREFIX.$name,
            fn () => static::where('option_name', $name)->value('option_value') ?? '__null__',
        );

        if ($value === '__null__') {
            return $default;
        }

        // Stored as JSON so arrays survive the round trip; anything written
        // before that convention still reads back as its plain string.
        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public static function put(string $name, mixed $value): void
    {
        static::updateOrCreate(
            ['option_name' => $name],
            ['option_value' => json_encode($value)],
        );

        Cache::forget(self::CACHE_PREFIX.$name);
    }

    public static function forget(string $name): void
    {
        static::where('option_name', $name)->delete();
        Cache::forget(self::CACHE_PREFIX.$name);
    }
}
