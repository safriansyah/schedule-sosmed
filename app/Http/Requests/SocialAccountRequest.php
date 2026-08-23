<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\SocialPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SocialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission(Permission::ManageAccounts);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'platform' => ['required', Rule::enum(SocialPlatform::class)],
            'name' => ['required', 'string', 'max:120'],
            'username' => ['nullable', 'string', 'max:120'],

            // On update the token may be left blank to keep the stored one.
            'access_token' => [$account ? 'nullable' : 'required', 'string', 'max:1000'],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.required' => 'Pilih platform.',
            'name.required' => 'Nama akun wajib diisi.',
            'access_token.required' => 'Access token wajib diisi.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'access_token' => trim((string) $this->input('access_token')) ?: null,
        ]);
    }
}
