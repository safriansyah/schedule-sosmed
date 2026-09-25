<?php

namespace App\Http\Requests;

use App\Enums\InteractionType;
use App\Enums\Permission;
use App\Enums\SocialPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::HandleInteractions->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Restricted to the channels config says we cannot read
            // automatically — typing in an Instagram comment by hand would
            // just duplicate what the sync already pulls.
            'channel' => ['required', Rule::in((array) config('crm.manual_channels'))],

            'type' => ['required', Rule::in(array_column(InteractionType::cases(), 'value'))],

            // One of the two is needed to know who this was, and the phone is
            // what makes a WhatsApp entry reachable later.
            'author_handle' => ['nullable', 'string', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:author_handle'],

            'author_name' => ['nullable', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'channel.in' => 'Kanal ini sudah ditarik otomatis — tidak perlu diinput manual.',
            'author_handle.required_without' => 'Isi username atau nomor WhatsApp-nya.',
            'phone.required_without' => 'Isi nomor WhatsApp atau username-nya.',
            'text.required' => 'Isi pesannya.',
            'occurred_at.before_or_equal' => 'Waktu tidak boleh di masa depan.',
        ];
    }

    /** @return array<string, string> value => label */
    public static function channelOptions(): array
    {
        return collect((array) config('crm.manual_channels'))
            ->mapWithKeys(fn (string $value) => [
                $value => SocialPlatform::tryFrom($value)?->label() ?? ucfirst($value),
            ])
            ->all();
    }
}
