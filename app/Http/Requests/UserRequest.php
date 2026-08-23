<?php

namespace App\Http\Requests;

use App\Enums\Permission;
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
            'password' => [
                $user ? 'nullable' : 'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],

            'is_active' => ['nullable', 'boolean'],
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
