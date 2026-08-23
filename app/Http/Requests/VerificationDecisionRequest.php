<?php

namespace App\Http\Requests;

use App\Models\Verification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerificationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decideVerification', $this->route('content'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approved', 'revision'])],

            'note' => [
                Rule::requiredIf(fn () => $this->input('action') === 'revision'),
                'nullable', 'string', 'max:2000',
            ],

            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'action.required' => 'Pilih hasil verifikasi.',
            'note.required' => 'Catatan wajib diisi saat meminta revisi.',
        ];
    }

    /**
     * Normalised checklist — every known item present as a boolean.
     *
     * @return array<string, bool>
     */
    public function checklist(): array
    {
        $checked = $this->input('checklist', []);

        return collect(Verification::CHECKLIST)
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => (bool) ($checked[$key] ?? false)])
            ->all();
    }
}
