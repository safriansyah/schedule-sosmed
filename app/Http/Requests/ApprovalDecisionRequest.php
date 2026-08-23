<?php

namespace App\Http\Requests;

use App\Enums\ApprovalAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApprovalDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decideApproval', $this->route('content'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(ApprovalAction::class)],

            // A note is mandatory whenever the content does not move forward.
            'note' => [
                Rule::requiredIf(fn () => in_array($this->input('action'), ['rejected', 'revision'], true)),
                'nullable', 'string', 'max:2000',
            ],

            'suggested_caption' => ['nullable', 'string', 'max:2200'],
            'suggested_hashtags' => ['nullable', 'string', 'max:600'],
            'suggested_schedule_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'action.required' => 'Pilih keputusan.',
            'note.required' => 'Catatan wajib diisi saat menolak atau meminta revisi.',
        ];
    }

    public function action(): ApprovalAction
    {
        return ApprovalAction::from($this->string('action')->toString());
    }

    /** @return array<string, mixed> */
    public function suggestions(): array
    {
        return array_filter([
            'caption' => $this->input('suggested_caption'),
            'hashtags' => $this->input('suggested_hashtags'),
            'schedule_at' => $this->input('suggested_schedule_at'),
        ]);
    }
}
