<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\CalendarEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        if (! $this->user()?->hasPermission(Permission::ManageCalendarNotes)) {
            return false;
        }

        // A note may only be edited by its author (or a super admin).
        return $event === null
            || $this->user()->isSuperAdmin()
            || $event->user_id === $this->user()->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(CalendarEvent::TYPES))],
            'note' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul catatan wajib diisi.',
            'type.required' => 'Pilih jenis catatan.',
            'starts_at.required' => 'Tanggal wajib diisi.',
            'ends_at.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_all_day' => $this->boolean('is_all_day')]);
    }
}
