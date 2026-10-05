<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Kata sandi wajib diisi.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // `is_active` is part of the credentials on purpose: a deactivated
        // account must not be able to sign in, and the failure stays generic
        // so we never reveal whether the email exists.
        $credentials = [...$this->only('email', 'password'), 'is_active' => true];

        // Jadwal login: checked only once the password has proved correct, so
        // the specific message below is shown only to the account's owner.
        $outsideSchedule = false;

        $allowed = Auth::attemptWhen($credentials, function ($user) use (&$outsideSchedule) {
            if ($user->loginAllowedAt(now())) {
                return true;
            }

            $outsideSchedule = true;

            return false;
        }, $this->boolean('remember'));

        if (! $allowed) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => $outsideSchedule
                    ? 'Akses login Anda berada di luar jadwal yang telah ditentukan.'
                    : 'Email atau kata sandi salah, atau akun Anda dinonaktifkan.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the request is not rate limited.
     *
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    /** Rate-limiting key scoped to the email + IP pair. */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
