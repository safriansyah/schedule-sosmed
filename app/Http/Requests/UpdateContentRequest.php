<?php

namespace App\Http\Requests;

use App\Rules\InstagramAspectRatio;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('content'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'hashtags' => ['nullable', 'string', 'max:600'],
            'mention' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'is_carousel' => ['nullable', 'boolean'],

            'social_account_ids' => ['required', 'array', 'min:1'],
            'social_account_ids.*' => ['uuid', 'exists:social_accounts,id'],

            // Media is optional on update — existing files are kept.
            'media' => ['nullable', 'array', 'max:10'],
            'media.*' => [
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime',
                'max:102400',
                new InstagramAspectRatio,
            ],

            'remove_media' => ['nullable', 'array'],
            'remove_media.*' => ['uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'social_account_ids.required' => 'Pilih minimal satu akun tujuan.',
            'media.*.mimetypes' => 'Format harus JPG, PNG, WebP, MP4, atau MOV.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_carousel' => $this->boolean('is_carousel')]);
    }
}
