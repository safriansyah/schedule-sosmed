<?php

/**
 * Export in all three formats, for students, tickets and follow-ups.
 *
 * The rule under test everywhere here: an export contains exactly what the
 * filtered screen showed — no more (an operator must not export another
 * operator's caseload) and no less.
 */

use App\Models\Student;
use App\Support\Spreadsheet\XlsxReader;
use App\Services\Students\StudentAssigner;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;

uses(DatabaseTransactions::class);

function exportStudents(int $count = 5): void
{
    foreach (range(1, $count) as $i) {
        Student::create([
            'nim' => 'EXP'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'nama' => "Mahasiswa Export {$i}",
            'kabupaten' => $i % 2 === 0 ? 'Bangka' : 'Belitung',
            'kecamatan' => 'Kecamatan Uji',
            'kategori_masalah' => 'ongoing_tidak_registrasi',
        ]);
    }
}

/** Streamed downloads only run their callback when it is drained. */
function download(TestResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('exports students as CSV using the active filter', function () {
    exportStudents(5);

    $response = $this->actingAs(admin())
        ->get(route('students.export', ['format' => 'csv', 'q' => 'EXP', 'kabupaten' => 'Bangka']));

    $response->assertOk();
    $csv = download($response);

    expect($csv)->toStartWith("\xEF\xBB\xBF")      // BOM, so Excel reads UTF-8
        ->and($csv)->toContain('NIM')
        ->and($csv)->toContain('EXP0000002')       // Bangka
        ->and($csv)->not->toContain('EXP0000001'); // Belitung — filtered out
});

it('exports students as JSON with named keys', function () {
    exportStudents(3);

    $response = $this->actingAs(admin())
        ->get(route('students.export', ['format' => 'json', 'q' => 'EXP']));

    $response->assertOk();

    $rows = json_decode(download($response), true);

    expect($rows)->toBeArray()->toHaveCount(3)
        ->and($rows[0])->toHaveKeys(['nim', 'nama', 'kabupaten', 'assignment_status'])
        ->and(collect($rows)->pluck('nim')->sort()->values()->all())
        ->toBe(['EXP0000001', 'EXP0000002', 'EXP0000003']);
});

it('exports students as a readable xlsx', function () {
    exportStudents(3);

    $response = $this->actingAs(admin())
        ->get(route('students.export', ['format' => 'xlsx', 'q' => 'EXP']));

    $response->assertOk();

    // A BinaryFileResponse writes a real file; read it back with our own reader.
    $path = $response->baseResponse->getFile()->getPathname();
    $rows = iterator_to_array((new XlsxReader($path))->rows());

    expect($rows[0])->toContain('NIM')
        ->and($rows)->toHaveCount(4)   // header + 3
        ->and($rows[1][0])->toBe('EXP0000001');
});

it('never exports another operator’s students', function () {
    exportStudents(5);

    $operator = operatorNamed('export-op@test.local');

    app(StudentAssigner::class)->assignSelected(
        Student::where('nim', 'like', 'EXP%')->limit(2)->pluck('id')->all(),
        $operator,
        admin(),
    );

    $response = $this->actingAs($operator)
        ->get(route('students.export', ['format' => 'json', 'q' => 'EXP']));

    $response->assertOk();

    // Only their own two, even though five match the filter.
    expect(json_decode(download($response), true))->toHaveCount(2);
});

it('exports tickets and their resolution', function () {
    $service = app(TicketService::class);

    $ticket = $service->createManual(['subject' => 'Tiket untuk diexport'], admin());
    $service->close($ticket, 'Sudah diselesaikan.', admin());

    $response = $this->actingAs(admin())
        ->get(route('tickets.export', ['format' => 'json', 'q' => $ticket->number]));

    $response->assertOk();

    $rows = json_decode(download($response), true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['number'])->toBe($ticket->number)
        ->and($rows[0]['status'])->toBe('Ditutup')      // enum label, not its value
        ->and($rows[0]['resolution_note'])->toBe('Sudah diselesaikan.');
});

it('exports the follow-up history', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual(['subject' => 'Perlu follow up'], admin());

    $service->addFollowUp($ticket, [
        'action' => 'ditelepon',
        'channel_used' => 'telepon',
        'response_text' => 'Sudah dihubungi lewat telepon.',
        'outcome' => 'positif',
    ], admin());

    $response = $this->actingAs(admin())
        ->get(route('reports.followUps.export', ['format' => 'csv']));

    $response->assertOk();

    expect(download($response))
        ->toContain('Sudah dihubungi lewat telepon.')
        ->toContain($ticket->number);
});

it('falls back to CSV when asked for a format it does not write', function () {
    exportStudents(1);

    $response = $this->actingAs(admin())
        ->get(route('students.export', ['format' => 'pdf', 'q' => 'EXP']));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('refuses to export without the permission', function () {
    exportStudents(1);

    // PIC may read students but holds no ExportData permission.
    $pic = operatorNamed('export-pic@test.local', \App\Enums\RoleName::Pic);

    expect($pic->hasPermission(\App\Enums\Permission::ExportData))->toBeFalse();

    $this->actingAs($pic)
        ->get(route('students.export', ['format' => 'csv']))
        ->assertForbidden();
});
