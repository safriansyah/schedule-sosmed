<?php

namespace App\Http\Controllers;

use App\Enums\Gender;
use App\Enums\GuestBookService;
use App\Enums\GuestBookStatus;
use App\Models\GuestBookEntry;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Buku Tamu / Antrian — the public half. No login on any of these.
 *
 * What a visitor can do is exactly: submit the form once (throttled), see
 * their OWN number afterwards (held in their session, never looked up by an
 * id in the URL), and watch the monitor, whose JSON is a whitelist of
 * number / short name / service / status. Nothing here reads or changes
 * anything an operator owns.
 */
class PublicGuestBookController extends Controller
{
    /** Largest paraf accepted, decoded. A signature PNG is a few KB. */
    private const MAX_SIGNATURE_BYTES = 300 * 1024;

    public function create(): View
    {
        return view('guest-book.form', [
            'types' => GuestBookService::types(),
            'serviceGroups' => GuestBookService::grouped(),
            'genders' => Gender::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Honeypot: a field no human sees. A bot that fills every input gets
        // a normal-looking redirect and no queue number.
        if (filled($request->input('website'))) {
            return redirect()->route('guest-book.create');
        }

        $data = $request->validate([
            'whatsapp' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'nim' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'service' => ['required', Rule::enum(GuestBookService::class)],
            // "Lainnya" says nothing by itself: the description has to.
            'description' => [
                Rule::requiredIf(fn () => GuestBookService::tryFrom((string) $request->input('service'))?->isOther() ?? false),
                'nullable', 'string', 'max:2000',
            ],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:'.(int) ceil(self::MAX_SIGNATURE_BYTES * 1.4)],
        ], [
            'signature.required' => 'Paraf wajib diisi — gambar paraf Anda pada kotak yang tersedia.',
            'signature.*' => 'Paraf tidak terbaca. Hapus lalu gambar ulang paraf Anda.',
            'nim.regex' => 'NIM hanya boleh berisi angka/huruf.',
            'description.required' => 'Untuk pilihan "Lainnya", jelaskan keperluan atau keluhan Anda pada Deskripsi.',
        ], [
            'whatsapp' => 'No. WhatsApp',
            'phone' => 'No. HP',
            'name' => 'nama',
            'gender' => 'jenis kelamin',
            'service' => 'jenis layanan',
            'description' => 'deskripsi',
        ]);

        $whatsapp = PhoneNumber::normalize($data['whatsapp']);
        $phone = PhoneNumber::normalize($data['phone']);

        $errors = array_filter([
            'whatsapp' => $whatsapp ? null : 'No. WhatsApp tidak valid. Contoh: 0812 3456 7890.',
            'phone' => $phone ? null : 'No. HP tidak valid. Contoh: 0812 3456 7890.',
        ]);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $entry = GuestBookEntry::take([
            'whatsapp' => $whatsapp,
            'phone' => $phone,
            'name' => Str::squish(strip_tags($data['name'])),
            'nim' => filled($data['nim'] ?? null) ? strtoupper(trim($data['nim'])) : null,
            'gender' => $data['gender'],
            'service' => $data['service'],
            'description' => filled($data['description'] ?? null) ? trim(strip_tags($data['description'])) : null,
            'signature_path' => $this->storeSignature($data['signature']),
            'ip_address' => $request->ip(),
        ]);

        // The confirmation page reads the entry from here — never from an id
        // in the URL — so nobody can page through other visitors' numbers.
        $request->session()->put('guest_book.entry_id', $entry->id);

        return redirect()->route('guest-book.done');
    }

    /** "Nomor antrian Anda: 007". Only ever the visitor's own entry. */
    public function done(Request $request): View|RedirectResponse
    {
        $entry = GuestBookEntry::find($request->session()->get('guest_book.entry_id'));

        if (! $entry) {
            return redirect()->route('guest-book.create');
        }

        $ahead = $entry->status === GuestBookStatus::Waiting
            ? GuestBookEntry::whereDate('queue_date', $entry->queue_date)
                ->where('status', GuestBookStatus::Waiting->value)
                ->where('queue_number', '<', $entry->queue_number)
                ->count()
            : 0;

        return view('guest-book.done', ['entry' => $entry, 'ahead' => $ahead]);
    }

    public function monitor(): View
    {
        return view('guest-book.monitor', ['initial' => $this->snapshot()]);
    }

    /** Polled by the monitor every few seconds. */
    public function feed(): JsonResponse
    {
        return response()
            ->json($this->snapshot())
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * Today's live queue, as the monitor shows it.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $entries = GuestBookEntry::query()
            ->today()
            ->active()
            ->orderBy('queue_number')
            ->limit(200)
            ->get(['id', 'queue_number', 'name', 'service', 'status']);

        $now = $entries->filter(fn ($e) => $e->status !== GuestBookStatus::Waiting)
            ->sortByDesc('queue_number')->values();

        return [
            'now' => $now->map->monitorPayload()->values()->all(),
            'waiting' => $entries->where('status', GuestBookStatus::Waiting)->map->monitorPayload()->values()->all(),
            'served_today' => GuestBookEntry::today()->whereNotIn('status', array_map(fn ($s) => $s->value, GuestBookStatus::active()))->count(),
            'updated_at' => now('Asia/Jakarta')->format('H:i:s'),
        ];
    }

    /**
     * Decodes the drawn paraf and keeps it on the private disk.
     *
     * The bytes are checked to really be a PNG, not merely to arrive labelled
     * as one, before anything is written.
     */
    private function storeSignature(string $dataUrl): string
    {
        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        if ($binary === false
            || strlen($binary) > self::MAX_SIGNATURE_BYTES
            || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")
            || @getimagesizefromstring($binary) === false) {
            throw ValidationException::withMessages([
                'signature' => 'Paraf tidak terbaca. Hapus lalu gambar ulang paraf Anda.',
            ]);
        }

        $path = 'guest-book/signatures/'.now('Asia/Jakarta')->format('Y/m').'/'.Str::uuid().'.png';

        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
