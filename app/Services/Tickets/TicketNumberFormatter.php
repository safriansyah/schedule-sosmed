<?php

namespace App\Services\Tickets;

use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Ticket numbers, from a list of admin-defined formats.
 *
 * It started as one hard-coded rule (`TKT-00001`), then one configurable
 * pattern. Neither fits how the institution actually files things: a finance
 * case and an academic one want to be tellable apart at a glance, so the admin
 * wants TKU-…, TKB-…, TKC-… side by side, each with a note saying what it is
 * for. So the setting is a LIST.
 *
 * Each format carries:
 *
 *   code    — short prefix the humans say out loud ("TKU")
 *   label   — what it is for ("Tiket Keuangan"), shown wherever one is chosen
 *   pattern — the text, with {PLACEHOLDER} tokens
 *   reset   — how often its counter goes back to 1
 *   default — exactly one is the fallback for tickets raised automatically
 *
 * Counters are PER FORMAT. TKU-0001 and TKB-0001 both exist, and neither
 * disturbs the other, because the sequence is read from the numbers already
 * issued under that format's own prefix.
 *
 * Uniqueness is NOT this class's promise. `tickets.number` is UNIQUE and
 * `Ticket::createWithNumber()` retries on collision; that is what actually
 * guarantees no duplicates, whatever an admin types here.
 */
class TicketNumberFormatter
{
    /** The list of formats. The old single-pattern keys are read as a fallback. */
    public const SETTING_FORMATS = 'ticket.number.formats';

    public const SETTING_PATTERN = 'ticket.number.pattern';

    public const SETTING_RESET = 'ticket.number.reset';

    public const DEFAULT_PATTERN = 'TKT-{SEQ:5}';

    public const DEFAULT_RESET = 'never';

    /** `tickets.number` is varchar(24). A pattern that cannot fit is rejected. */
    public const MAX_LENGTH = 24;

    /** More than this and the settings screen stops being a list and becomes a mess. */
    public const MAX_FORMATS = 20;

    /**
     * Every token the pattern understands. The settings screen renders this
     * list, so the help can never drift from what the code supports.
     *
     * @return array<string, string>
     */
    public static function tokens(): array
    {
        return [
            '{YYYY}' => 'Tahun 4 digit — 2026',
            '{YY}' => 'Tahun 2 digit — 26',
            '{MM}' => 'Bulan 2 digit — 09',
            '{DD}' => 'Tanggal 2 digit — 25',
            '{SEQ}' => 'Nomor urut tanpa nol di depan — 1, 2, 3',
            '{SEQ:n}' => 'Nomor urut dengan n digit — {SEQ:4} jadi 0001',
        ];
    }

    /** @return array<string, string> */
    public static function resetOptions(): array
    {
        return [
            'never' => 'Tidak pernah — nomor terus naik selamanya',
            'yearly' => 'Setiap tahun — kembali ke 1 tiap 1 Januari',
            'monthly' => 'Setiap bulan — kembali ke 1 tiap tanggal 1',
            'daily' => 'Setiap hari — kembali ke 1 tiap hari',
        ];
    }

    /* -----------------------------------------------------------------
     | The list
     * ----------------------------------------------------------------- */

    /**
     * Every configured format, normalised.
     *
     * An install that never opened this screen still has the old single
     * pattern in settings, so that is read as a one-entry list rather than
     * silently replaced — otherwise upgrading would renumber every future
     * ticket without anyone asking for it.
     *
     * @return array<int, array{code: string, label: string, pattern: string, reset: string, default: bool}>
     */
    public function formats(): array
    {
        $stored = Setting::get(self::SETTING_FORMATS);

        if (! is_array($stored) || $stored === []) {
            return [$this->normalise([
                'code' => 'TKT',
                'label' => 'Umum',
                'pattern' => (string) (Setting::get(self::SETTING_PATTERN) ?: self::DEFAULT_PATTERN),
                'reset' => (string) (Setting::get(self::SETTING_RESET) ?: self::DEFAULT_RESET),
                'default' => true,
            ])];
        }

        $formats = array_values(array_map(fn ($f) => $this->normalise((array) $f), $stored));

        // Exactly one default, always. A list with none would leave automatic
        // ticket creation with nothing to use.
        if (! collect($formats)->contains(fn ($f) => $f['default'])) {
            $formats[0]['default'] = true;
        }

        return $formats;
    }

    /** @return array{code: string, label: string, pattern: string, reset: string, default: bool} */
    public function defaultFormat(): array
    {
        $formats = $this->formats();

        return collect($formats)->firstWhere('default', true) ?? $formats[0];
    }

    /**
     * One format by code, or the default when the code is unknown.
     *
     * Falling back rather than throwing on purpose: a ticket must still get a
     * number when someone deletes a format that an old form still references.
     *
     * @return array{code: string, label: string, pattern: string, reset: string, default: bool}
     */
    public function format(?string $code): array
    {
        if (blank($code)) {
            return $this->defaultFormat();
        }

        return collect($this->formats())->firstWhere('code', strtoupper(trim($code)))
            ?? $this->defaultFormat();
    }

    /** For a select box: code => "TKU — Tiket Keuangan (TKU-202609-0001)". */
    public function options(): array
    {
        $out = [];

        foreach ($this->formats() as $f) {
            $out[$f['code']] = sprintf(
                '%s — %s (%s)',
                $f['code'],
                $f['label'] ?: 'tanpa catatan',
                $this->preview($f['pattern'], 1),
            );
        }

        return $out;
    }

    /**
     * Replace the whole list.
     *
     * @param  array<int, array<string, mixed>>  $formats
     *
     * @throws InvalidArgumentException with a message meant for the admin
     */
    public function saveFormats(array $formats): void
    {
        $clean = [];
        $codes = [];

        foreach (array_values($formats) as $i => $raw) {
            $format = $this->normalise((array) $raw);
            $position = $i + 1;

            if ($format['code'] === '') {
                throw new InvalidArgumentException("Format #{$position}: kode tidak boleh kosong.");
            }

            if (preg_match('/^[A-Z0-9]{2,8}$/', $format['code']) !== 1) {
                throw new InvalidArgumentException(
                    "Format #{$position}: kode \"{$format['code']}\" harus 2–8 huruf/angka tanpa spasi."
                );
            }

            if (in_array($format['code'], $codes, true)) {
                // Two formats sharing a code would share a counter and hand out
                // the same number twice.
                throw new InvalidArgumentException("Kode \"{$format['code']}\" dipakai dua kali.");
            }

            $this->validatePattern($format['pattern'], $position);

            $codes[] = $format['code'];
            $clean[] = $format;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('Minimal satu format harus ada.');
        }

        if (count($clean) > self::MAX_FORMATS) {
            throw new InvalidArgumentException('Maksimum '.self::MAX_FORMATS.' format.');
        }

        // Exactly one default — the last one ticked wins, and if none was,
        // the first entry takes it.
        $chosen = null;

        foreach ($clean as $i => $f) {
            if ($f['default']) {
                $chosen = $i;
            }
        }

        foreach ($clean as $i => &$f) {
            $f['default'] = $i === ($chosen ?? 0);
        }
        unset($f);

        Setting::put(self::SETTING_FORMATS, $clean);
    }

    /* -----------------------------------------------------------------
     | Producing a number
     * ----------------------------------------------------------------- */

    /** The next number to try, for one format. */
    public function next(?string $code = null, ?Carbon $at = null): string
    {
        $format = $this->format($code);
        $at = $at ?? now();

        [$head, $tail] = $this->split($format['pattern'], $at);

        return $head.$this->pad($this->nextSequence($head, $tail, $at, $format), $format['pattern']).$tail;
    }

    /**
     * What a pattern would produce for a given sequence — for the preview.
     * Never reads or moves a counter.
     */
    public function preview(string $pattern, int $sequence = 1, ?Carbon $at = null): string
    {
        [$head, $tail] = $this->split($pattern, $at ?? now());

        return $head.$this->pad($sequence, $pattern).$tail;
    }

    /**
     * True when the pattern's own date tokens already restart the counter.
     *
     * `TKU-{YYYY}{MM}-{SEQ:4}` produces a different prefix every month, so the
     * sequence necessarily starts over whatever the reset setting says. The
     * screen explains this rather than offering a choice that does nothing.
     */
    public function resetIsImplied(string $pattern): bool
    {
        foreach (['{YYYY}', '{YY}', '{MM}', '{DD}'] as $token) {
            if (str_contains($pattern, $token)) {
                return true;
            }
        }

        return false;
    }

    /** What the counter actually restarts on, pattern and setting combined. */
    public function effectiveReset(array $format): string
    {
        if (! $this->resetIsImplied($format['pattern'])) {
            return $format['reset'];
        }

        return match (true) {
            str_contains($format['pattern'], '{DD}') => 'daily',
            str_contains($format['pattern'], '{MM}') => 'monthly',
            default => 'yearly',
        };
    }

    /**
     * Reject a pattern before it is saved.
     *
     * @throws InvalidArgumentException with a message meant for the admin
     */
    public function validatePattern(string $pattern, ?int $position = null): void
    {
        $where = $position ? "Format #{$position}: " : '';
        $pattern = trim($pattern);

        if ($pattern === '') {
            throw new InvalidArgumentException($where.'pola tidak boleh kosong.');
        }

        if (substr_count($pattern, '{SEQ') !== 1) {
            // No counter: every ticket in the period gets the same number and
            // the unique index rejects the second one — the ticket form simply
            // stops working. Two counters: no way to read the number back.
            throw new InvalidArgumentException($where.'pola wajib memuat tepat satu {SEQ} atau {SEQ:n}.');
        }

        preg_match_all('/\{([A-Za-z]+)(?::([^}]*))?\}/', $pattern, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $token = $match[1];

            if (! in_array($token, ['YYYY', 'YY', 'MM', 'DD', 'SEQ'], true)) {
                throw new InvalidArgumentException($where."token {{$token}} tidak dikenal.");
            }

            if ($token !== 'SEQ' && isset($match[2])) {
                throw new InvalidArgumentException($where."token {{$token}} tidak menerima angka di belakang titik dua.");
            }

            if ($token === 'SEQ' && isset($match[2])) {
                $digits = $match[2];

                if (! ctype_digit($digits) || (int) $digits < 1 || (int) $digits > 12) {
                    throw new InvalidArgumentException($where.'panjang {SEQ:n} harus angka antara 1 dan 12.');
                }
            }
        }

        // Leftover braces mean a typo like "TKU-{YYYY" that would otherwise end
        // up inside the ticket number itself.
        $stripped = (string) preg_replace('/\{[A-Za-z]+(?::[^}]*)?\}/', '', $pattern);

        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            throw new InvalidArgumentException($where.'ada kurung kurawal yang tidak menutup atau token salah tulis.');
        }

        // Check the widest realistic case, not today's: a pattern that fits at
        // #1 must still fit at #99999.
        $widest = $this->preview($pattern, 99999);

        if (mb_strlen($widest) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                '%shasil pola terlalu panjang (%d karakter, maksimum %d).',
                $where,
                mb_strlen($widest),
                self::MAX_LENGTH,
            ));
        }
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /** @return array{code: string, label: string, pattern: string, reset: string, default: bool} */
    private function normalise(array $format): array
    {
        $pattern = trim((string) ($format['pattern'] ?? '')) ?: self::DEFAULT_PATTERN;
        $reset = (string) ($format['reset'] ?? self::DEFAULT_RESET);

        return [
            'code' => strtoupper(trim((string) ($format['code'] ?? ''))),
            'label' => trim((string) ($format['label'] ?? '')),
            'pattern' => $pattern,
            'reset' => array_key_exists($reset, self::resetOptions()) ? $reset : self::DEFAULT_RESET,
            'default' => (bool) ($format['default'] ?? false),
        ];
    }

    /**
     * Split the pattern at its sequence token into the text before and after,
     * with the date tokens already resolved.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $pattern, Carbon $at): array
    {
        $parts = preg_split('/\{SEQ(?::\d+)?\}/', $pattern, 2);

        $resolve = fn (string $text) => strtr($text, [
            '{YYYY}' => $at->format('Y'),
            '{YY}' => $at->format('y'),
            '{MM}' => $at->format('m'),
            '{DD}' => $at->format('d'),
        ]);

        return [$resolve($parts[0] ?? ''), $resolve($parts[1] ?? '')];
    }

    private function pad(int $sequence, string $pattern): string
    {
        return preg_match('/\{SEQ:(\d+)\}/', $pattern, $m) === 1
            ? str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT)
            : (string) $sequence;
    }

    /**
     * One past the highest sequence already issued under this format.
     *
     * Reads the highest EXISTING number rather than counting rows. Counting
     * breaks the moment there is a gap — force-delete ticket #2 of three and
     * the count says 2, handing out #3, which already exists; the insert then
     * fails on the unique index and retrying produces the same number for
     * ever. The maximum has no such failure mode.
     *
     * Soft-deleted tickets are included: their numbers still occupy the unique
     * index, so reusing one would fail the insert.
     */
    private function nextSequence(string $head, string $tail, Carbon $at, array $format): int
    {
        $escape = fn (string $v) => str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $v);

        $query = Ticket::withTrashed()
            ->where('number', 'like', $escape($head).'%'.$escape($tail));

        // With no date token the prefix cannot isolate a period, so the reset
        // setting is applied to created_at instead.
        if (! $this->resetIsImplied($format['pattern'])) {
            match ($format['reset']) {
                'yearly' => $query->whereYear('created_at', $at->year),
                'monthly' => $query->whereYear('created_at', $at->year)->whereMonth('created_at', $at->month),
                'daily' => $query->whereDate('created_at', $at->toDateString()),
                default => null,
            };
        }

        // Longest first, then lexicographic: that is numeric order for padded
        // AND unpadded sequences, where a plain string MAX() would rank '9'
        // above '10'.
        $highest = $query
            ->orderByRaw('CHAR_LENGTH(number) DESC')
            ->orderByDesc('number')
            ->value('number');

        if ($highest === null) {
            return 1;
        }

        $middle = mb_substr($highest, mb_strlen($head));

        if ($tail !== '') {
            $middle = mb_substr($middle, 0, -mb_strlen($tail));
        }

        // A number left over from an older pattern may not be a plain integer.
        // Starting again at 1 is right: the unique index and the retry loop
        // walk past anything that happens to collide.
        return ctype_digit($middle) ? ((int) $middle) + 1 : 1;
    }
}
