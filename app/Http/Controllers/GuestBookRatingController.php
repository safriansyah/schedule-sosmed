<?php

namespace App\Http\Controllers;

use App\Enums\GuestBookService;
use App\Enums\Permission;
use App\Models\GuestBookEntry;
use App\Services\ActivityLogger;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Penilaian Layanan: the stars and reviews visitors gave their visits.
 *
 * Everyone who runs the Buku Tamu reads them (Manager, Operator Frontdesk);
 * only the admin may correct or remove one — a desk that could delete its
 * own one-star reviews would make the whole page meaningless.
 */
class GuestBookRatingController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ManageGuestBook->value);

        [$query, $filters] = $this->filtered($request);

        // Stats over the dates and search, before the star filter narrows it,
        // so the distribution always shows the whole picture.
        $base = $this->filtered($request, withStars: false)[0];
        $distribution = (clone $base)->selectRaw('rating, COUNT(*) AS total')->groupBy('rating')->pluck('total', 'rating');
        $total = (int) $distribution->sum();

        return view('guest-book.admin.ratings', [
            'entries' => $query->with('completer:id,name')->orderByDesc('rated_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'total' => $total,
            'average' => $total ? (float) (clone $base)->avg('rating') : null,
            'distribution' => $distribution,
            // 4 and 5 stars: "puas".
            'satisfied' => (int) (($distribution[4] ?? 0) + ($distribution[5] ?? 0)),
            'canManage' => $request->user()->can(Permission::ManageGuestBookRatings->value),
        ]);
    }

    /** The admin corrects a rating (a wrong tap, an abusive word in the review). */
    public function update(Request $request, GuestBookEntry $entry): RedirectResponse
    {
        $this->authorize(Permission::ManageGuestBookRatings->value);
        abort_unless($entry->hasRating(), 404);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'review' => ['nullable', 'string', 'max:1000'],
        ], [], ['rating' => 'bintang', 'review' => 'ulasan']);

        $before = ['rating' => $entry->rating, 'review' => $entry->review];

        $entry->forceFill([
            'rating' => (int) $data['rating'],
            'review' => filled($data['review'] ?? null) ? trim(strip_tags($data['review'])) : null,
        ])->save();

        $this->log->log(
            'guest_book.rating_updated',
            "Penilaian antrian {$entry->displayNumber()} ({$entry->name}) diubah: {$before['rating']} → {$entry->rating} bintang",
            $entry,
            ['before' => $before, 'after' => ['rating' => $entry->rating, 'review' => $entry->review]],
        );

        return back()->with('success', 'Penilaian diperbarui.');
    }

    /** The admin removes a rating; the visit itself stays. */
    public function destroy(GuestBookEntry $entry): RedirectResponse
    {
        $this->authorize(Permission::ManageGuestBookRatings->value);
        abort_unless($entry->hasRating(), 404);

        $before = ['rating' => $entry->rating, 'review' => $entry->review];

        $entry->forceFill(['rating' => null, 'review' => null, 'rated_at' => null])->save();

        $this->log->log(
            'guest_book.rating_deleted',
            "Penilaian antrian {$entry->displayNumber()} ({$entry->name}) dihapus ({$before['rating']} bintang)",
            $entry,
            ['before' => $before],
        );

        return back()->with('success', 'Penilaian dihapus.');
    }

    /** Download what the list shows — same dates, stars and search, newest first. */
    public function export(Request $request, Exporter $exporter)
    {
        $this->authorize(Permission::ManageGuestBook->value);

        [$query, $filters] = $this->filtered($request);

        $this->log->log('guest_book.ratings_exported', "Export penilaian layanan {$filters['from']} s/d {$filters['to']}", null, [
            'filters' => array_filter($filters),
        ]);

        return $exporter->download(
            format: 'xlsx',
            filename: 'penilaian-layanan-'.str_replace('-', '', $filters['from']).'-sd-'.str_replace('-', '', $filters['to']),
            columns: [
                'rated_at' => 'Waktu Penilaian',
                'date' => 'Tanggal Kunjungan',
                'number' => 'No. Antrian',
                'name' => 'Nama',
                'nim' => 'NIM',
                'type' => 'Jenis Kunjungan',
                'service' => 'Layanan / Keluhan',
                'rating' => 'Bintang (1-5)',
                'label' => 'Keterangan',
                'review' => 'Ulasan',
                'completed_by' => 'Petugas yang Menyelesaikan',
            ],
            query: $query->with('completer:id,name'),
            mapper: fn (GuestBookEntry $e) => [
                $e->rated_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i'),
                $e->queue_date->format('d/m/Y'),
                $e->displayNumber(),
                $e->name,
                $e->nim,
                $e->service->typeLabel(),
                $e->service->label(),
                $e->rating,
                self::LABELS[$e->rating] ?? '',
                $e->review,
                $e->completer?->name,
            ],
        );
    }

    public const LABELS = [1 => 'Sangat buruk', 2 => 'Buruk', 3 => 'Cukup', 4 => 'Baik', 5 => 'Sangat baik'];

    /**
     * Rated visits within the dates (of the rating; this month by default),
     * the search and the star count.
     *
     * @return array{0: Builder, 1: array<string, mixed>}
     */
    private function filtered(Request $request, bool $withStars = true): array
    {
        $date = function (?string $value, Carbon $fallback): Carbon {
            try {
                return filled($value) ? Carbon::createFromFormat('Y-m-d', $value, 'Asia/Jakarta') : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $from = $date($request->input('from'), now('Asia/Jakarta')->startOfMonth())->startOfDay();
        $to = $date($request->input('to'), now('Asia/Jakarta'))->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $stars = in_array((int) $request->input('rating'), [1, 2, 3, 4, 5], true) ? (int) $request->input('rating') : null;
        $q = trim((string) $request->input('q'));
        $type = in_array($request->input('type'), array_keys(GuestBookService::types()), true) ? $request->input('type') : null;

        $query = GuestBookEntry::query()
            ->whereNotNull('rating')
            // Stored in the app timezone (WIB), like every timestamp here.
            ->whereBetween('rated_at', [$from, $to])
            ->when($withStars && $stars, fn (Builder $b) => $b->where('rating', $stars))
            ->when($type, fn (Builder $b) => $b->whereIn('service', GuestBookService::valuesOf($type)))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('nim', 'like', "%{$q}%")
                ->orWhere('review', 'like', "%{$q}%")));

        return [$query, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rating' => $stars,
            'q' => $q,
            'type' => $type,
        ]];
    }
}
