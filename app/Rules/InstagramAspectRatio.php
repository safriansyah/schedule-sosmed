<?php

namespace App\Rules;

use App\Support\MediaValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class InstagramAspectRatio implements ValidationRule
{
    /**
     * Reject uploaded images whose aspect ratio Instagram won't accept.
     * Videos (and non-image files) are skipped — Reels allow flexible ratios.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ((is_array($value) ? $value : [$value]) as $file) {
            if (! is_object($file) || ! method_exists($file, 'getRealPath')) {
                continue;
            }

            $path = $file->getRealPath();

            if ($path && ($error = MediaValidator::imageRatioError($path))) {
                $fail($error);
            }
        }
    }
}
