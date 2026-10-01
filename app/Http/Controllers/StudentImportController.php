<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\StudentImport;
use App\Services\Export\Exporter;
use App\Services\Students\StudentImportService;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Upload → preview → confirm → import.
 *
 * The preview step is not decoration: the real spreadsheet has not been seen
 * yet, so the admin gets to check how the headings were understood before
 * thousands of rows are written on a guess.
 */
class StudentImportController extends Controller
{
    public function __construct(private readonly StudentImportService $service) {}

    /** Upload form plus the history of previous imports. */
    public function index(): View
    {
        $this->authorize(Permission::ImportStudents->value);

        return view('students.import.index', [
            'imports' => StudentImport::with('creator:id,name')->latest()->paginate(10),
            'mapping' => (array) config('students.mapping', []),
        ]);
    }

    /** Stores the file and shows what we made of it. Imports nothing yet. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ImportStudents->value);

        $request->validate([
            // By extension, not 'mimes': the content is sniffed by the service, and
            // PHP's guess calls some genuine .xlsx files a plain zip and refuses them.
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:51200'],
        ], [], ['file' => 'berkas']);

        // A file that cannot be read is the admin's to fix, not a server
        // error: say what is wrong on the upload form instead of a 500 page.
        try {
            $preview = $this->service->preview($request->file('file'), $request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'file' => $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Berkas tidak bisa dibaca. Pastikan berkas .xlsx atau .csv tidak rusak, lalu coba lagi.',
            ]);
        }

        return redirect()
            ->route('students.import.preview', $preview['import'])
            ->with('success', 'Berkas terbaca. Periksa pemetaan kolom sebelum melanjutkan.');
    }

    public function preview(Request $request, StudentImport $import): View
    {
        $this->authorize(Permission::ImportStudents->value);

        // An explicit tab choice wins over the automatic one. Validated against
        // the file's real tabs so a hand-edited URL cannot point the importer
        // at something that is not there.
        if ($request->filled('sheet')) {
            $sheets = $this->service->sheetsFor($import);

            if (in_array($request->input('sheet'), $sheets, true)) {
                $import->forceFill(['sheet' => $request->input('sheet')])->save();
            }
        }

        // Re-read rather than trusting the session, so a refresh or a
        // bookmarked URL shows the same thing.
        $preview = $this->service->previewStored($import);

        return view('students.import.preview', $preview + ['import' => $import]);
    }

    /** The confirm button: hand the file to the queue. */
    public function confirm(StudentImport $import): RedirectResponse
    {
        $this->authorize(Permission::ImportStudents->value);

        if ($import->status === StudentImport::STATUS_PROCESSING && ! $import->isStalled()) {
            return back()->withErrors(['import' => 'Import ini sedang berjalan.']);
        }

        $this->service->dispatch($import);

        return redirect()
            ->route('students.import.show', $import)
            ->with('success', config('students.queued', true)
                ? 'Import dijalankan di latar belakang. Halaman ini memperbarui sendiri.'
                : 'Import selesai dijalankan.');
    }

    /** Result page: totals, and the per-row errors. */
    public function show(StudentImport $import): View|RedirectResponse
    {
        $this->authorize(Permission::ImportStudents->value);

        // Never confirmed, so nothing is running: showing the progress card
        // here would spin forever. Its next step is the preview.
        if ($import->awaitingConfirmation()) {
            return redirect()
                ->route('students.import.preview', $import)
                ->with('success', 'Berkas ini belum diimport. Periksa pemetaan kolom lalu klik "Import Sekarang".');
        }

        return view('students.import.show', [
            'import' => $import->load('creator:id,name'),
        ]);
    }

    /**
     * "Jalankan Langsung": the escape hatch when the queue worker is not
     * running (or was killed mid-import), so a stuck import can still finish.
     */
    public function run(StudentImport $import): RedirectResponse
    {
        $this->authorize(Permission::ImportStudents->value);

        if (! $import->isStalled()) {
            return redirect()->route('students.import.show', $import);
        }

        $this->service->runNow($import);

        return redirect()
            ->route('students.import.show', $import)
            ->with('success', 'Import dijalankan langsung.');
    }

    /** Polled by the result page while the import runs. */
    public function status(StudentImport $import): JsonResponse
    {
        $this->authorize(Permission::ImportStudents->value);

        return response()->json([
            'status' => $import->status,
            'progress' => $import->progress,
            'total' => $import->total_rows,
            'imported' => $import->imported_count,
            'updated' => $import->updated_count,
            'failed' => $import->failed_count,
            'duplicate' => $import->duplicate_count,
            'finished' => $import->isFinished(),
            'stalled' => $import->isStalled(),
            'error' => $import->error_message,
        ]);
    }

    /**
     * "Download Error Data" — the rejected rows as a spreadsheet, so they can
     * be fixed in Excel and re-uploaded without hunting through the list.
     */
    public function errors(StudentImport $import)
    {
        $this->authorize(Permission::ImportStudents->value);

        $errors = $import->errors ?? [];

        if ($errors === []) {
            return back()->withErrors(['errors' => 'Tidak ada baris gagal pada import ini.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'import_errors_').'.xlsx';

        (new XlsxWriter)->write(
            $path,
            ['Baris', 'NIM', 'Alasan'],
            array_map(fn ($e) => [$e['line'] ?? '', $e['nim'] ?? '', $e['reason'] ?? ''], $errors),
        );

        return response()
            ->download($path, "baris-gagal-import-{$import->id}.xlsx")
            ->deleteFileAfterSend();
    }

    /**
     * A ready-made template with the headings the importer recognises — the
     * cheapest way to stop a malformed upload before it happens.
     */
    public function template()
    {
        $this->authorize(Permission::ImportStudents->value);

        $headings = array_keys((array) config('students.mapping', []));

        $path = tempnam(sys_get_temp_dir(), 'template_').'.xlsx';

        (new XlsxWriter)->write($path, $headings, [[
            '010123456', 'NAC202601001', 'Contoh Mahasiswa', 'contoh@email.com', '081234567890',
            'Manajemen', 'FEB', '2026.1', 'Bangka', 'Sungailiat', 'Sungailiat',
            'Sudah', 'Belum', 'Belum', 'Belum', 'Sudah registrasi, belum bayar', 'Excel Semester 2026.1', '',
        ]]);

        return response()
            ->download($path, 'template-import-mahasiswa.xlsx')
            ->deleteFileAfterSend();
    }
}
