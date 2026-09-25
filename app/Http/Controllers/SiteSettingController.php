<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\SiteBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pengaturan Sistem → Identitas Website.
 *
 * Name, tagline, logo and favicon. Kept apart from `SettingsController`, which
 * is the signed-in person's own profile and password: one is "who am I", the
 * other is "what is this app called", and only the second needs an
 * institution-wide permission.
 */
class SiteSettingController extends Controller
{
    public function __construct(
        private readonly SiteBranding $branding,
        private readonly ActivityLogger $log,
    ) {}

    public function edit(): View
    {
        $this->authorize(Permission::ManageSettings->value);

        return view('settings.site', [
            'name' => $this->branding->name(),
            'tagline' => $this->branding->tagline(),
            'logoUrl' => $this->branding->logoUrl(),
            'faviconUrl' => $this->branding->faviconUrl(),
            'configName' => config('app.name'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ManageSettings->value);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'tagline' => ['nullable', 'string', 'max:96'],
            // SVG is deliberately absent: it is a script-bearing format, and
            // this file is served to every visitor from our own origin.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:png,ico,webp', 'max:512'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ], [], [
            'name' => 'nama website',
            'tagline' => 'tagline',
            'logo' => 'logo',
            'favicon' => 'favicon',
        ]);

        $before = [
            'name' => $this->branding->name(),
            'tagline' => $this->branding->tagline(),
        ];

        Setting::put(SiteBranding::NAME, trim($data['name']));
        Setting::put(SiteBranding::TAGLINE, trim((string) ($data['tagline'] ?? '')));

        foreach ([
            ['logo', 'remove_logo', SiteBranding::LOGO],
            ['favicon', 'remove_favicon', SiteBranding::FAVICON],
        ] as [$field, $removeField, $key]) {
            if ($request->boolean($removeField)) {
                $this->branding->forgetImage($key);

                continue;
            }

            // No file and no removal means "leave it alone" — a partial form
            // must never wipe an image the admin did not touch.
            if ($file = $request->file($field)) {
                $this->branding->putImage($key, $file);
            }
        }

        $this->log->log('settings.updated', 'Mengubah identitas website', null, [
            'from' => $before,
            'to' => ['name' => $this->branding->name(), 'tagline' => $this->branding->tagline()],
        ]);

        return back()->with('success', 'Identitas website disimpan.');
    }
}
