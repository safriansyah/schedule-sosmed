<?php

namespace App\Http\Requests;

use App\Models\Content;
use App\Rules\InstagramAspectRatio;
use Illuminate\Foundation\Http\FormRequest;

class StoreContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Content::class);
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
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'is_carousel' => ['nullable', 'boolean'],

            'social_account_ids' => ['required', 'array', 'min:1'],
            'social_account_ids.*' => ['uuid', 'exists:social_accounts,id'],

            'media' => ['required', 'array', 'min:1', 'max:10'],
            'media.*' => [
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime',
                'max:102400', // 100 MB
                new InstagramAspectRatio,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'social_account_ids.required' => 'Pilih minimal satu akun tujuan.',
            'media.required' => 'Unggah minimal satu gambar atau video.',
            'media.*.mimetypes' => 'Format harus JPG, PNG, WebP, MP4, atau MOV.',
            'media.*.max' => 'Ukuran file maksimal 100 MB.',
            'scheduled_at.after_or_equal' => 'Jadwal terbit tidak boleh di masa lalu.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_carousel' => $this->boolean('is_carousel')]);
    }
}
