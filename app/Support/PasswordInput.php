<?php

namespace App\Support;

/**
 * Accepts a password that is either typed in plain, or already bcrypt-hashed
 * somewhere else.
 *
 * The institution hands accounts over by pasting a hash from an online bcrypt
 * generator, so the app has to take one. That is not as simple as "detect a
 * hash and store it", because of a trap PHP sets:
 *
 *   password_verify() accepts $2a$, $2b$ and $2y$ — they are the same
 *   algorithm, the letter is only a version marker from the 2011
 *   crypt_blowfish fix.
 *
 *   password_get_info() on PHP 8.2 recognises ONLY $2y$. The other two come
 *   back as algo NULL / 'unknown'.
 *
 * Laravel builds on the second one. So a $2a$ hash — which is what most online
 * generators emit — fails `Hash::isHashed()`, gets hashed a SECOND time by the
 * model cast, and the account silently ends up with a password nobody knows.
 * Worse, if such a hash ever reached the column unhashed, `Hash::check()`
 * would throw on the login attempt rather than return false.
 *
 * So the prefix is normalised to $2y$ on the way in. That is safe, and it was
 * verified rather than assumed: for the same salt and cost the three prefixes
 * produce byte-identical digests, checked against ASCII, non-ASCII, emoji and
 * a 72-character password.
 */
class PasswordInput
{
    /**
     * A complete bcrypt hash: prefix, two-digit cost, then 53 characters of
     * salt+digest in bcrypt's own base64 alphabet. Exactly 60 characters.
     */
    private const BCRYPT = '/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/';

    /** Anything that is trying to be a bcrypt hash, complete or not. */
    private const BCRYPT_ISH = '/^\$2[axby]?\$/';

    /** True when the value is a well-formed bcrypt hash we can store as-is. */
    public static function isBcryptHash(?string $value): bool
    {
        return $value !== null && preg_match(self::BCRYPT, $value) === 1;
    }

    /**
     * True when someone clearly meant to paste a hash but it did not arrive
     * whole — truncated, wrapped by the clipboard, or with a stray space.
     *
     * This case has to be REJECTED, not hashed: storing it as a plain password
     * would produce an account whose password is a fragment of a hash, and the
     * person who pasted it would have no idea. A malformed paste is the most
     * likely way this feature goes wrong, so it is the one that gets an error
     * message.
     */
    public static function looksLikeBrokenHash(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return preg_match(self::BCRYPT_ISH, $value) === 1 && ! self::isBcryptHash($value);
    }

    /**
     * The value to store, or null when this is not a hash and should be
     * hashed normally.
     *
     * $2a$ and $2b$ are rewritten to $2y$ so PHP's own tooling recognises
     * them; the digest is untouched.
     */
    public static function asStorableHash(?string $value): ?string
    {
        if (! self::isBcryptHash($value)) {
            return null;
        }

        return '$2y$'.substr((string) $value, 4);
    }
}
