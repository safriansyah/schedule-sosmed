<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Services\Students\StudentStats;
use App\Services\Students\StudentTicketGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Generate Ticket" on the hand-out screen: every student in the current list
 * gets a ticket so the case has somewhere to be worked and followed up.
 *
 * Its own controller rather than another method on the assignment one —
 * raising tickets and handing out students are two different decisions that
 * happen to share a screen, and they need different permissions.
 */
class StudentTicketController extends Controller
{
    public function __construct(private readonly StudentTicketGenerator $generator) {}

    public function store(Request $request): RedirectResponse
    {
        // Creating tickets, not assigning students — so this is the ticket
        // permission, even though the button lives on the student screen.
        $this->authorize(Permission::CreateTickets->value);

        $filters = $request->only(
            'q', 'kondisi', 'semester', 'segmen', 'status_dp', 'operator', 'import',
            ...StudentStats::REGION_LEVELS,
        );

        // No more "generate everything": a ticket is raised for students an
        // operator holds, in a chosen region — the same scope the "Assign
        // Wilayah & Ticket" button on /students/unsigned uses.
        $hasRegion = array_filter(array_intersect_key($filters, array_flip(StudentStats::REGION_LEVELS)), fn ($v) => filled($v)) !== [];
        $hasOperator = filled($filters['operator'] ?? null) && (string) $filters['operator'] !== '0';

        if (! $hasRegion || ! $hasOperator) {
            return back()->withErrors([
                'generate' => 'Pilih operator dan wilayah terlebih dahulu — gunakan "Assign Wilayah & Ticket".',
            ]);
        }

        $result = $this->generator->generate($filters, $request->user());

        if ($result['created'] === 0) {
            return back()->withErrors([
                'generate' => $result['skipped'] > 0
                    ? "Semua {$result['skipped']} mahasiswa pada daftar ini sudah punya tiket."
                    : 'Tidak ada mahasiswa yang cocok dengan filter saat ini.',
            ]);
        }

        $message = "{$result['created']} tiket dibuat dari data mahasiswa.";

        // Pressing the button twice is safe, and saying so out loud is what
        // stops someone pressing it a third time to be sure.
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} mahasiswa dilewati karena sudah punya tiket.";
        }

        return back()->with('success', $message);
    }
}
