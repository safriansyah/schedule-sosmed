<?php

namespace App\Http\Requests;

use Closure;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageDatasets) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('datasets.max_upload_kb', 102400),
                'mimes:json,txt',
                'mimetypes:application/json,text/plain,text/json',
                function (string $attr, mixed $value, Closure $fail) {
                    if (! $value || ! $value->isValid()) {
                        $fail('Berkas yang diunggah tidak valid.');

                        return;
                    }
                    $head = ltrim((string) file_get_contents($value->getRealPath(), false, null, 0, 64));
                    if ($head === '' || ($head[0] !== '[' && $head[0] !== '{')) {
                        $fail('Isi berkas bukan JSON yang valid.');
                    }
                },
            ],
        ];
    }
}
