<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Ticket;
use App\Services\ActivityLogger;
use App\Services\Tickets\TicketNumberFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Ticketing → Format ID Tiket.
 *
 * The admin keeps a LIST of formats — TKU for finance, TKB for something
 * else — each with a note saying what it is for, because a bare code is
 * unreadable to whoever inherits the system. One is the default, used by every
 * ticket the app raises on its own.
 *
 * The screen has to answer two questions before anything is saved: what will
 * the next number look like, and what happens to the numbers already issued.
 * It shows a live preview per row for the first, and says plainly — old
 * numbers are never rewritten — for the second.
 */
class TicketSettingController extends Controller
{
    public function __construct(
        private readonly TicketNumberFormatter $formatter,
        private readonly ActivityLogger $log,
    ) {}

    public function edit(Request $request): View
    {
        $this->authorize(Permission::ManageSettings->value);

        $formats = $this->formatter->formats();

        return view('tickets.settings', [
            'formats' => $formats,
            // Per format: what the next number would be, and how many tickets
            // already carry it. "Delete" is a different decision when 400
            // tickets are already numbered that way.
            'usage' => $this->usage($formats),
            'tokens' => TicketNumberFormatter::tokens(),
            'resetOptions' => TicketNumberFormatter::resetOptions(),
            'maxLength' => TicketNumberFormatter::MAX_LENGTH,
            'maxFormats' => TicketNumberFormatter::MAX_FORMATS,
            'ticketCount' => Ticket::count(),
            'lastNumber' => Ticket::withTrashed()->latest('id')->value('number'),
            'examples' => $this->examples(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ManageSettings->value);

        $data = $request->validate([
            'formats' => ['required', 'array', 'min:1', 'max:'.TicketNumberFormatter::MAX_FORMATS],
            'formats.*.code' => ['required', 'string', 'max:8'],
            'formats.*.label' => ['nullable', 'string', 'max:64'],
            'formats.*.pattern' => ['required', 'string', 'max:64'],
            'formats.*.reset' => ['required', 'string', 'in:'.implode(',', array_keys(TicketNumberFormatter::resetOptions()))],
            'default' => ['nullable', 'integer'],
        ], [], [
            'formats' => 'daftar format',
            'formats.*.code' => 'kode',
            'formats.*.pattern' => 'pola',
        ]);

        // The radio carries the index of the default row; fold it into the
        // list so the service gets one shape to validate.
        $formats = array_values($data['formats']);

        foreach ($formats as $i => &$format) {
            $format['default'] = (int) ($data['default'] ?? 0) === $i;
        }
        unset($format);

        $before = $this->formatter->formats();

        try {
            $this->formatter->saveFormats($formats);
        } catch (InvalidArgumentException $e) {
            // The service owns the rules, so its message is the one the admin
            // sees — no second copy here to drift out of step.
            return back()->withErrors(['formats' => $e->getMessage()])->withInput();
        }

        $this->log->log('settings.updated', 'Mengubah format ID tiket', null, [
            'from' => $before,
            'to' => $this->formatter->formats(),
        ]);

        $default = $this->formatter->defaultFormat();

        return back()->with(
            'success',
            count($formats).' format disimpan. Nomor berikutnya untuk '.$default['code'].': '.$this->formatter->next($default['code']),
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Next number and tickets-already-issued, per format.
     *
     * @param  array<int, array<string, mixed>>  $formats
     * @return array<string, array{next: string, issued: int}>
     */
    private function usage(array $formats): array
    {
        $out = [];

        foreach ($formats as $format) {
            // The prefix up to the first token is what the existing numbers
            // share, which is enough to count them without re-deriving the
            // whole pattern.
            $prefix = preg_split('/\{/', $format['pattern'], 2)[0] ?? '';

            $out[$format['code']] = [
                'next' => $this->formatter->next($format['code']),
                'issued' => $prefix === ''
                    ? 0
                    : Ticket::withTrashed()->where('number', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%')->count(),
            ];
        }

        return $out;
    }

    /**
     * Ready-made patterns, rendered so the admin picks by looking rather than
     * by reading token syntax.
     *
     * @return array<int, array{pattern: string, label: string, sample: string}>
     */
    private function examples(): array
    {
        $patterns = [
            'TKT-{SEQ:5}' => 'Sederhana — nomor urut saja',
            'TKU-{YYYY}{MM}-{SEQ:4}' => 'Per bulan — mudah dicari di arsip',
            'TKB-{YYYY}-{SEQ:5}' => 'Per tahun',
            'TKC{YY}{MM}{DD}-{SEQ:3}' => 'Per hari — untuk volume tinggi',
        ];

        $out = [];

        foreach ($patterns as $pattern => $label) {
            $out[] = [
                'pattern' => $pattern,
                'label' => $label,
                'sample' => $this->formatter->preview($pattern, 1),
            ];
        }

        return $out;
    }
}
