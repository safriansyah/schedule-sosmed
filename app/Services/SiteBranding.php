<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * The name, logo and favicon the app wears.
 *
 * These were `config('app.name')` and nothing else, which means changing them
 * was a deploy. They belong to whoever runs the institution, not to whoever
 * deploys the code, so they live in settings and are editable from a screen.
 *
 * Read on every page render, so every value is cached; `Setting` busts its own
 * cache on write.
 */
class SiteBranding
{
    public const NAME = 'site.name';

    public const TAGLINE = 'site.tagline';

    public const LOGO = 'site.logo';

    public const FAVICON = 'site.favicon';

    /** Falls back to config so a fresh install still has a name. */
    public function name(): string
    {
        $name = Setting::get(self::NAME);

        return filled($name) ? (string) $name : (string) config('app.name', 'Monitoring');
    }

    public function tagline(): string
    {
        $tagline = Setting::get(self::TAGLINE);

        return filled($tagline) ? (string) $tagline : 'Sosmed & CRM';
    }

    /** Public URL of the uploaded logo, or null to fall back to the drawn mark. */
    public function logoUrl(): ?string
    {
        return $this->urlFor(self::LOGO);
    }

    public function faviconUrl(): ?string
    {
        return $this->urlFor(self::FAVICON);
    }

    /**
     * Replace one image, deleting whatever it replaces.
     *
     * The old file is removed rather than orphaned: branding gets changed a
     * handful of times, but an install that never cleans up accumulates files
     * nobody can identify later.
     */
    public function putImage(string $key, \Illuminate\Http\UploadedFile $file): void
    {
        $this->forgetImage($key);

        Setting::put($key, $file->store('branding', 'public'));
    }

    public function forgetImage(string $key): void
    {
        $path = Setting::get($key);

        if (filled($path) && Storage::disk('public')->exists((string) $path)) {
            Storage::disk('public')->delete((string) $path);
        }

        Setting::forget($key);
    }

    private function urlFor(string $key): ?string
    {
        $path = Setting::get($key);

        if (blank($path)) {
            return null;
        }

        // A setting pointing at a file someone deleted by hand would otherwise
        // render a broken image on every page.
        if (! Storage::disk('public')->exists((string) $path)) {
            return null;
        }

        return Storage::disk('public')->url((string) $path);
    }
}
