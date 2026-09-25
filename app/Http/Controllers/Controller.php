<?php

namespace App\Http\Controllers;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * What an update actually changed, as attribute => [from, to].
     *
     * Used to write meaningful audit entries ("nomor HP: 0812… → 0813…")
     * instead of "record updated".
     *
     * Comparison is done on a normalised string form rather than by casting
     * the model attribute directly: an enum-cast attribute throws on
     * (string) $model->status, and a date-cast one compares an object against
     * the "Y-m-d" the form submitted and reports a change every single time.
     *
     * @param  array<string, mixed>  $data  the validated input
     * @return array<string, array{from: mixed, to: mixed}>
     */
    protected function changes(Model $model, array $data): array
    {
        $changes = [];

        foreach ($data as $key => $new) {
            $old = $model->getAttribute($key);

            if ($this->comparable($old) === $this->comparable($new)) {
                continue;
            }

            $changes[$key] = ['from' => $this->comparable($old), 'to' => $this->comparable($new)];
        }

        return $changes;
    }

    /** A form the audit log can store and two values can be compared on. */
    private function comparable(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $value instanceof BackedEnum => (string) $value->value,
            // Date only: a date field posted as "2026-09-20" must not look
            // different from the stored 2026-09-20 00:00:00.
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i') === $value->format('Y-m-d 00:00')
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i'),
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
