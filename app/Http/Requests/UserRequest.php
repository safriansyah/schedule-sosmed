<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Rules\NotABrokenHash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission(Permission::ManageUsers);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:180',
                Rule::unique('users', 'email')->ignore($user)->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'role_id' => ['required', 'exists:roles,id'],

            // Password is required on create, optional on update.
            //
            // A complete bcrypt hash may be pasted here instead of a password
            // — it satisfies the strength rules on its own (60 characters,
            // letters and numbers), so no exception is needed for it. What
            // does need catching is a hash that arrived in pieces; see the
            // rule.
            'password' => [
                $user ? 'nullable' : 'required',
                'confirmed',
                new NotABrokenHash,
                Password::min(8)->letters()->numbers(),
            ],

            'is_active' => ['nullable', 'boolean'],

            // Jadwal login (Super Admin only — see UserController).
            'login_schedule_enabled' => ['nullable', 'boolean'],
            'login_start_date' => ['nullable', 'date_format:Y-m-d'],
            'login_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:login_start_date'],
            'login_start_time' => ['nullable', 'date_format:H:i'],
            'login_end_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                // An enabled schedule with nothing in it would silently allow
                // everything — almost certainly not what was meant.
                if ($this->boolean('login_schedule_enabled')
                    && ! $this->filled(['login_start_date'])
                    && ! $this->filled(['login_end_date'])
                    && ! $this->filled(['login_start_time'])
                    && ! $this->filled(['login_end_time'])) {
                    $validator->errors()->add('login_schedule_enabled', 'Isi tanggal dan/atau jam login bila pembatasan diaktifkan.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.unique' => 'Email ini sudah digunakan.',
            'role_id.required' => 'Pilih role pengguna.',
            'password.required' => 'Kata sandi wajib diisi.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'login_end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'login_start_time.date_format' => 'Format jam mulai HH:MM, misalnya 08:00.',
            'login_end_time.date_format' => 'Format jam selesai HH:MM, misalnya 17:00.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'login_schedule_enabled' => $this->boolean('login_schedule_enabled'),
        ]);
    }
}
