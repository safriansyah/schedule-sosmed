<?php

namespace App\Support;

/**
 * Indonesian phone numbers, normalised to E.164 without the plus (628123…).
 *
 * The same person writes their number as 0812…, +62812…, 62 812-345, or
 * "0812 345 (WA)". Without one canonical form the contact database fills up
 * with duplicates of the same human, which is exactly what the UID is meant to
 * prevent.
 */
class PhoneNumber
{
    /** Returns null when the input cannot be a real Indonesian mobile number. */
    public static function normalize(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // Order matters. Strip any zeros sitting in front of the country code
        // FIRST — "062…" and "0062…" are the country code with an international
        // access prefix, not a national number. Doing the generic leading-zero
        // rule first would turn 062… into 6262….
        $digits = preg_replace('/^0+(?=62)/', '', $digits) ?? $digits;

        // Leading 0 is the national trunk prefix — swap it for the country code.
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        // A bare 8xxxxxxxx (people often drop the 0 when typing in a form).
        if (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        // "+62 0812…" — country code with the trunk prefix left attached.
        // Safe because every real Indonesian mobile number begins 62-8.
        if (str_starts_with($digits, '620')) {
            $digits = '62'.substr($digits, 3);
        }

        if (! str_starts_with($digits, '628')) {
            return null;
        }

        // Indonesian mobile numbers run 9–13 digits after the country code.
        $length = strlen($digits);

        return ($length >= 11 && $length <= 15) ? $digits : null;
    }

    /** Display form: 0812-3456-7890. */
    public static function pretty(?string $e164): ?string
    {
        if (blank($e164)) {
            return null;
        }

        $local = '0'.substr($e164, 2);

        return trim(chunk_split($local, 4, '-'), '-');
    }

    /** wa.me link for the click-to-chat button in the inbox. */
    public static function waLink(?string $e164, ?string $message = null): ?string
    {
        if (blank($e164)) {
            return null;
        }

        return 'https://wa.me/'.$e164.(filled($message) ? '?text='.rawurlencode($message) : '');
    }

    /**
     * Pulls the first plausible Indonesian number out of free text — people
     * drop their WhatsApp straight into a comment ("wa aku 0812...").
     */
    public static function extractFrom(?string $text): ?string
    {
        if (blank($text)) {
            return null;
        }

        // Tolerates spaces, dots and dashes between groups of digits.
        if (! preg_match('/(?:\+?62|0)8[\d\s.\-]{7,15}\d/', $text, $match)) {
            return null;
        }

        return self::normalize($match[0]);
    }
}
