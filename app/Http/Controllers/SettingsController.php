<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Personal settings — available to every authenticated user, regardless of
 * role. Nothing here touches privileges; that lives in user management.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function edit(): View
    {
        return view('settings.edit', ['user' => request()->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:180',
                Rule::unique('users', 'email')->ignore($user)->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
        ], [
            'name.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.unique' => 'Email ini sudah digunakan.',
        ]);

        $user->fill($data)->save();

        $this->log->log('user.profile_updated', 'Profil diperbarui', $user);

        return back()->with('toast', ['message' => 'Profil diperbarui.', 'type' => 'success']);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'current_password.current_password' => 'Kata sandi saat ini salah.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $user = $request->user();
        $user->password = Hash::make($request->input('password'));
        $user->save();

        $this->log->log('user.password_changed', 'Kata sandi diubah', $user);

        return back()->with('toast', ['message' => 'Kata sandi berhasil diubah.', 'type' => 'success']);
    }
}
