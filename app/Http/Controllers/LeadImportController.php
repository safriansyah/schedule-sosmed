<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Enums\Permission;
use App\Models\LeadImport;
use App\Services\Leads\LeadImportService;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/** Pick the source → upload → check the columns → import → result. */
class LeadImportController extends Controller
{
    public function __construct(private readonly LeadImportService $service) {}

    public function index(): View
    {
        $this->authorize(Permission::ImportLeads->value);

        return view('leads.import.index', [
            'imports' => LeadImport::with('creator:id,name')->latest('id')->paginate(10),
            'sources' => LeadSource::options(),
            'mapping' => LeadImportService::MAPPING,
            'labels' => LeadImportService::LABELS,
            'required' => LeadImportService::REQUIRED,
        ]);
    }

    /** Stores the file and shows what we made of it. Imports nothing yet. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ImportLeads->value);

        $data = $request->validate([
            'source' => ['required', Rule::enum(LeadSource::class)],
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:20480'],
        ], [
            'source.required' => 'Pilih sumber data terlebih dahulu.',
        ], ['file' => 'berkas', 'source' => 'sumber data']);

        try {
            $import = $this->service->store($request->file('file'), LeadSource::from($data['source']), $request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors([
                'file' => $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Berkas tidak bisa dibaca. Pastikan berkas .xlsx atau .csv tidak rusak, lalu coba lagi.',
            ]);
        }

        return redirect()
            ->route('leads.import.preview', $import)
            ->with('success', 'Berkas terbaca. Periksa pemetaan kolom sebelum melanjutkan.');
    }

    public function preview(Request $request, LeadImport $import): View|RedirectResponse
    {
        $this->authorize(Permission::ImportLeads->value);

        if (! $import->awaitingConfirmation()) {
            return redirect()->route('leads.import.show', $import);
        }

        // A tab chosen by hand, but only one the file really has.
        if ($request->filled('sheet') && in_array($request->input('sheet'), $this->service->sheetsFor($import), true)) {
            $import->forceFill(['sheet' => $request->input('sheet')])->save();
        }

        // A file that cannot be read after all is the admin's to fix: say so on
        // the upload form instead of a 500, and close this import off.
        try {
            $preview = $this->service->preview($import);
        } catch (Throwable $e) {
            report($e);

            $import->forceFill([
                'status' => LeadImport::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();

            return redirect()->route('leads.import.index')->withErrors([
                'file' => $e instanceof RuntimeException ? $e->getMessage() : 'Berkas tidak bisa dibaca. Unggah ulang berkasnya.',
            ]);
        }

        return view('leads.import.preview', $preview + [
            'import' => $import,
            'labels' => LeadImportService::LABELS,
        ]);
    }

    public function confirm(LeadImport $import): RedirectResponse
    {
        $this->authorize(Permission::ImportLeads->value);

        // Pressed twice, or the page re-submitted: the first run stands.
        if (! $import->awaitingConfirmation()) {
            return redirect()->route('leads.import.show', $import);
        }

        $this->service->import($import);

        return redirect()
            ->route('leads.import.show', $import)
            ->with($import->status === LeadImport::STATUS_COMPLETED ? 'success' : 'error',
                $import->status === LeadImport::STATUS_COMPLETED ? 'Import data lead selesai.' : 'Import data lead gagal.');
    }

    public function show(LeadImport $import): View|RedirectResponse
    {
        $this->authorize(Permission::ImportLeads->value);

        if ($import->awaitingConfirmation()) {
            return redirect()->route('leads.import.preview', $import);
        }

        return view('leads.import.show', ['import' => $import->load('creator:id,name')]);
    }

    /** The rejected rows, to fix in Excel and upload again. */
    public function errors(LeadImport $import)
    {
        $this->authorize(Permission::ImportLeads->value);

        abort_if(empty($import->errors), 404);

        $path = tempnam(sys_get_temp_dir(), 'lead_errors_');
        @unlink($path);

        (new XlsxWriter)->write(
            $path .= '.xlsx',
            ['Baris', 'No HP', 'Alasan'],
            array_map(fn ($e) => [$e['line'] ?? '', $e['no_hp'] ?? '', $e['reason'] ?? ''], $import->errors),
        );

        return response()->download($path, "baris-gagal-import-lead-{$import->id}.xlsx")->deleteFileAfterSend();
    }

    public function template()
    {
        $this->authorize(Permission::ImportLeads->value);

        $path = tempnam(sys_get_temp_dir(), 'lead_template_');
        @unlink($path);

        (new XlsxWriter)->write($path .= '.xlsx', ['Nama', 'No HP', 'Kota / Kabupaten', 'Asal Sekolah'], [
            ['Contoh Calon Mahasiswa', '081234567890', 'Pangkalpinang', 'SMA Negeri 1 Pangkalpinang'],
            ['Contoh Tanpa Sekolah', '082198765432', 'Bangka Tengah', ''],
        ]);

        return response()->download($path, 'template-import-data-lead.xlsx')->deleteFileAfterSend();
    }
}
