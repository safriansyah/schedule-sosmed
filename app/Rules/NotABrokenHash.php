<?php

namespace App\Rules;

use App\Support\PasswordInput;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Catches a bcrypt hash that did not arrive whole.
 *
 * Pasting a hash is supported (see PasswordInput). The failure mode worth
 * guarding is the half-paste: a clipboard that wrapped, a truncated copy, a
 * stray space. That string still passes every strength rule — it is long, has
 * letters and numbers — so without this it would be stored as a LITERAL
 * password, and the account would end up with a password that is a fragment of
 * a hash. Nobody would find out until they tried to log in.
 *
 * Deliberately narrow: it only complains about values that start like a bcrypt
 * hash. An ordinary password is never touched.
 */
class NotABrokenHash implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (PasswordInput::looksLikeBrokenHash($value)) {
            $fail('Ini terlihat seperti hash bcrypt yang tidak lengkap. Hash bcrypt panjangnya tepat 60 karakter dan diawali $2y$, $2a$, atau $2b$ — salin ulang seluruhnya, atau ketik kata sandi biasa.');
        }
    }
}
