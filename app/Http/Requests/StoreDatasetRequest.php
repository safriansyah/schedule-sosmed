<?php

namespace App\Http\Requests;

use Closure;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class StoreDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageDatasets) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('datasets.max_upload_kb', 102400),
                'mimes:json,txt',
                'mimetypes:application/json,text/plain,text/json',
                fn (string $attr, mixed $value, Closure $fail) => $this->assertLooksLikeJson($value, $fail),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The JSON file may not be larger than :max KB.',
            'file.mimes' => 'Only .json files are accepted.',
        ];
    }

    /**
     * Cheap sniff so obviously-bad uploads fail fast (no full parse).
     */
    protected function assertLooksLikeJson(mixed $value, Closure $fail): void
    {
        if (! $value || ! $value->isValid()) {
            $fail('The uploaded file is invalid.');

            return;
        }

        $head = ltrim((string) file_get_contents($value->getRealPath(), false, null, 0, 64));

        if ($head === '' || ($head[0] !== '[' && $head[0] !== '{')) {
            $fail('The file does not appear to contain valid JSON (expected an array or object).');
        }
    }
}
