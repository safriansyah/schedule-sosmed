<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkInteractionRequest extends FormRequest
{
    /** Actions that may be applied to a whole selection. */
    public const ACTIONS = ['assign', 'in_progress', 'done', 'close', 'ignore', 'reclassify'];

    public function authorize(): bool
    {
        return $this->user()->can(Permission::HandleInteractions->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],

            // Capped so a runaway "select all" cannot lock a table for minutes.
            // The UI selects one page (25) at a time, so this is generous.
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['uuid', 'exists:interactions,id'],

            'assigned_to' => [
                Rule::requiredIf(fn () => $this->input('action') === 'assign'),
                'nullable', 'integer', 'exists:users,id',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'Pilih dulu interaksi yang ingin diproses.',
            'ids.max' => 'Maksimal 200 interaksi sekali proses.',
            'action.required' => 'Pilih tindakan yang ingin dijalankan.',
            'assigned_to.required' => 'Pilih petugas yang akan ditugaskan.',
        ];
    }
}
