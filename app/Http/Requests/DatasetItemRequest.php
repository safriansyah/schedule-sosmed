<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared rules for creating / inline-editing a dataset row.
 */
class DatasetItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageDatasets) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:191'],
            'username' => ['nullable', 'string', 'max:191'],
            'external_id' => ['nullable', 'string', 'max:191'],
            'platform' => ['nullable', 'string', 'max:64'],
            'followers' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'following' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'posts' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'is_valid' => ['sometimes', 'boolean'],
            'is_qualified' => ['sometimes', 'boolean'],
            'profile_url' => ['nullable', 'string', 'url', 'max:512'],
            'reason' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();

        foreach (['followers', 'following', 'posts'] as $n) {
            $data[$n] = (int) ($data[$n] ?? 0);
        }
        $data['is_valid'] = $this->boolean('is_valid');
        $data['is_qualified'] = $this->boolean('is_qualified');
        $data['platform'] = $data['platform'] ? strtolower(trim($data['platform'])) : null;

        return $data;
    }
}
