<?php

/**
 * The import pipeline, including the hand-written .xlsx reader and writer.
 *
 * Those two have no library behind them, so they get real round-trip coverage:
 * write a workbook, read it back, and check the values survived.
 */

use App\Enums\AssignmentStatus;
use App\Enums\StudentCondition;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\Students\StudentImportService;
use App\Services\Students\StudentRowMapper;
use App\Support\Spreadsheet\CsvReader;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Runs the import inside the request so the assertions can read the result
    // immediately rather than waiting on a queue worker.
    config()->set('students.queued', false);
});

/** Writes a CSV in the scratch area and returns an UploadedFile for it. */
function csvUpload(string $contents, string $name = 'mahasiswa.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import_').'.csv';
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

function runImport(UploadedFile $file): StudentImport
{
    $service = app(StudentImportService::class);
    $preview = $service->preview($file, User::first());

    $service->import($preview['import']);

    return $preview['import']->refresh();
}

/* -----------------------------------------------------------------
 | CSV
 * ----------------------------------------------------------------- */

it('imports a CSV and leaves every row unassigned', function () {
    $import = runImport(csvUpload(<<<'CSV'
    nim,nama,kabupaten,kecamatan,kondisi
    IMP0000001,Ahmad Fauzan,Bangka,Sungailiat,"Sudah registrasi, belum bayar"
    IMP0000002,Budi Santoso,Bangka,Belinyu,Billing NAC belum bayar
    IMP0000003,Citra Lestari,Bangka Tengah,Koba,Belum registrasi mata kuliah
    CSV));

    expect($import->status)->toBe(StudentImport::STATUS_COMPLETED)
        ->and($import->total_rows)->toBe(3)
        ->and($import->imported_count)->toBe(3)
        ->and($import->failed_count)->toBe(0);

    $students = Student::where('nim', 'like', 'IMP%')->get();

    expect($students)->toHaveCount(3)
        ->and($students->pluck('assignment_status')->unique()->all())
        ->toBe([AssignmentStatus::Unassigned])
        ->and($students->pluck('assigned_to')->unique()->all())->toBe([null]);
});

it('reads a semicolon-delimited CSV with a BOM', function () {
    // What Excel writes on an Indonesian-locale Windows machine.
    $import = runImport(csvUpload("\xEF\xBB\xBFnim;nama;kabupaten\nIMP0000010;Dedi Saputra;Pangkalpinang\n"));

    expect($import->imported_count)->toBe(1)
        ->and(Student::where('nim', 'IMP0000010')->value('nama'))->toBe('Dedi Saputra');
});

it('reports the rows it could not use without stopping the import', function () {
    $import = runImport(csvUpload(<<<'CSV'
    nim,nama
    IMP0000020,Baris Bagus
    ,Baris Tanpa NIM
    IMP0000021,Baris Bagus Lagi
    CSV));

    expect($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(1)
        ->and($import->errors)->toHaveCount(1)
        ->and($import->errors[0]['reason'])->toContain('NIM kosong')
        // Line 3 as Excel counts it: header is line 1.
        ->and($import->errors[0]['line'])->toBe(3);
});

it('flags a NIM the spreadsheet mangled into scientific notation', function () {
    $import = runImport(csvUpload("nim,nama\n1.0123456E+8,Rusak\n"));

    expect($import->imported_count)->toBe(0)
        ->and($import->failed_count)->toBe(1)
        ->and($import->errors[0]['reason'])->toContain('notasi ilmiah');
});

it('counts a NIM repeated inside one file as a duplicate', function () {
    $import = runImport(csvUpload(<<<'CSV'
    nim,nama
    IMP0000030,Pertama
    IMP0000030,Kedua
    CSV));

    expect($import->imported_count)->toBe(1)
        ->and($import->duplicate_count)->toBe(1)
        ->and(Student::where('nim', 'IMP0000030')->value('nama'))->toBe('Pertama');
});

it('updates an existing student without taking them off their operator', function () {
    $operator = operatorNamed('import-op@test.local');

    $student = Student::create([
        'nim' => 'IMP0000040',
        'nama' => 'Nama Lama',
        'assignment_status' => AssignmentStatus::FollowUp->value,
        'assigned_to' => $operator->id,
        'assigned_at' => now(),
    ]);

    $import = runImport(csvUpload("nim,nama,kabupaten\nIMP0000040,Nama Baru,Bangka\n"));
    $student->refresh();

    expect($import->updated_count)->toBe(1)
        ->and($import->imported_count)->toBe(0)
        ->and($student->nama)->toBe('Nama Baru')
        ->and($student->kabupaten)->toBe('Bangka')
        // The whole point: a re-upload must not reshuffle the hand-out.
        ->and($student->assigned_to)->toBe($operator->id)
        ->and($student->assignment_status)->toBe(AssignmentStatus::FollowUp);
});

it('keeps unrecognised columns instead of dropping them', function () {
    runImport(csvUpload("nim,nama,Kolom Aneh,Catatan Khusus\nIMP0000050,Eka,nilai aneh,catatan penting\n"));

    $student = Student::where('nim', 'IMP0000050')->first();

    expect($student->extra)->toBe([
        'Kolom Aneh' => 'nilai aneh',
        'Catatan Khusus' => 'catatan penting',
    ]);
});

it('fails the whole import when the NIM column is missing', function () {
    $import = runImport(csvUpload("nama,kabupaten\nTanpa NIM,Bangka\n"));

    expect($import->status)->toBe(StudentImport::STATUS_FAILED)
        ->and($import->error_message)->toContain('nim');
});

/* -----------------------------------------------------------------
 | Column mapping
 * ----------------------------------------------------------------- */

it('matches headings regardless of case, spacing and punctuation', function () {
    $mapper = app(StudentRowMapper::class);
    $mapper->bindHeadings(['NIM', 'No. HP', 'nama_lengkap', 'KAB/KOTA', 'Semester Terakhir']);

    expect($mapper->mapping())
        ->toHaveKeys(['nim', 'no_hp', 'nama', 'kabupaten', 'semester_terakhir'])
        ->and($mapper->missingRequired())->toBe([]);
});

it('normalises phone numbers and title-cases regions', function () {
    runImport(csvUpload("nim,nama,no hp,kabupaten\nIMP0000060,Fajar,081234567890,BANGKA SELATAN\n"));

    $student = Student::where('nim', 'IMP0000060')->first();

    expect($student->no_hp)->toBe('6281234567890')
        ->and($student->no_hp_raw)->toBe('081234567890')
        // Title case so "BANGKA SELATAN" and "bangka selatan" land on one
        // filter value rather than two.
        ->and($student->kabupaten)->toBe('Bangka Selatan');
});

it('infers the condition from the status columns when there is no summary', function () {
    runImport(csvUpload(<<<'CSV'
    nim,status registrasi,status pembayaran,status billing nac,status registrasi matkul
    IMP0000070,Sudah,Belum,Sudah,Sudah
    IMP0000071,Belum,Belum,Belum,Belum
    IMP0000072,Sudah,Sudah,Sudah,Belum
    CSV));

    expect(Student::where('nim', 'IMP0000070')->value('kategori_masalah'))
        ->toBe(StudentCondition::AdmisiTidakBayar)
        ->and(Student::where('nim', 'IMP0000071')->value('kategori_masalah'))
        ->toBe(StudentCondition::OngoingTidakRegistrasi)
        ->and(Student::where('nim', 'IMP0000072')->value('kategori_masalah'))
        ->toBe(StudentCondition::MabaBelumRegMk);
});

it('keeps the seeder’s status flags consistent with the importer’s reading', function () {
    // The dummy data and the importer must agree: if the seeder writes flags
    // the mapper would read as a different condition, every demo is a lie.
    $mapper = app(StudentRowMapper::class);
    $mapper->bindHeadings([
        'nim', 'status registrasi', 'status pembayaran', 'status billing nac', 'status registrasi matkul',
    ]);

    // A list of pairs, not an enum-keyed map: enum cases cannot be array keys.
    $cases = [
        [StudentCondition::AdmisiTidakBayar, ['Sudah', 'Belum', 'Sudah', 'Sudah']],
        [StudentCondition::OngoingBillingPending, ['Sudah', 'Belum', 'Belum', 'Sudah']],
        [StudentCondition::MabaBelumRegMk, ['Sudah', 'Sudah', 'Sudah', 'Belum']],
        [StudentCondition::OngoingTidakRegistrasi, ['Belum', 'Belum', 'Belum', 'Belum']],
    ];

    foreach ($cases as [$expected, $flags]) {
        $result = $mapper->map(['X', ...$flags]);

        expect($result['data']['kategori_masalah'])->toBe($expected->value);
    }
});

it('reads the Sub Katagori wording exactly as the real file writes it', function () {
    // Quoted, comma-suffixed, and carrying the file's own two typos
    // ("Semeter", "Semseter"). These strings are copied from the supplied
    // DATA INDUK PROGRES REGISTRASI, not tidied up.
    $fromFile = [
        "'Non Aktif DN'," => StudentCondition::NonAktifDn,
        "'Ongoing Tidak Registrasi Semeter Lalu'," => StudentCondition::OngoingTidakRegistrasi,
        "'Ongoing Billing Pending Semester Lalu'," => StudentCondition::OngoingBillingPending,
        "'Admisi Tidak Bayar Semester Lalu'," => StudentCondition::AdmisiTidakBayar,
        "'Maba Belum Bayar Reg MK Semseter Lalu'," => StudentCondition::MabaBelumBayarMk,
        "'Admisi Kurang Berkas Semester Lalu'," => StudentCondition::AdmisiKurangBerkas,
        "'Admisi Gagal Validasi Semester Lalu'," => StudentCondition::AdmisiGagalValidasi,
        "'Maba Belum Reg MK Semester Lalu'," => StudentCondition::MabaBelumRegMk,
    ];

    foreach ($fromFile as $raw => $expected) {
        expect(StudentCondition::guess($raw))->toBe($expected);
    }

    // Corrected spelling must keep working, so fixing the source file later
    // does not silently reclassify thousands of rows.
    expect(StudentCondition::guess('Ongoing Tidak Registrasi Semester Lalu'))
        ->toBe(StudentCondition::OngoingTidakRegistrasi)
        ->and(StudentCondition::guess('entah apa'))->toBe(StudentCondition::Other)
        ->and(StudentCondition::guess('/'))->toBe(StudentCondition::Other)
        ->and(StudentCondition::guess(null))->toBe(StudentCondition::Other)
        // Our own export must re-import unchanged.
        ->and(StudentCondition::guess('ongoing_tidak_registrasi'))->toBe(StudentCondition::OngoingTidakRegistrasi);
});

it('covers every Sub Katagori the real file contains', function () {
    // Eight from the registration export, two from the admission lists that
    // are imported separately (admisi belum bayar, admisi baru), plus the
    // catch-all. Anything new lands on Other and shows up in the raw column
    // rather than disappearing.
    expect(StudentCondition::cases())->toHaveCount(11);

    // The two admission lists must not swallow each other, nor the Maba rows
    // that also say "belum bayar".
    expect(StudentCondition::guess('Admisi Belum Bayar'))->toBe(StudentCondition::AdmisiBelumBayar)
        ->and(StudentCondition::guess('Admisi Baru'))->toBe(StudentCondition::AdmisiBaru)
        ->and(StudentCondition::guess('Maba Belum Bayar Reg MK Semeter Lalu'))->toBe(StudentCondition::MabaBelumBayarMk)
        ->and(StudentCondition::guess('Admisi Tidak Bayar Semester Lalu'))->toBe(StudentCondition::AdmisiTidakBayar);
});

/* -----------------------------------------------------------------
 | XLSX round trip
 * ----------------------------------------------------------------- */

it('writes and reads back an xlsx workbook', function () {
    $path = tempnam(sys_get_temp_dir(), 'roundtrip_').'.xlsx';

    (new XlsxWriter)->write($path, ['nim', 'nama', 'kabupaten'], [
        ['010123456', 'Ahmad Fauzan', 'Bangka'],
        ['010123457', 'Budi "Si Kecil" Santoso', 'Bangka Tengah'],
        ['010123458', 'Citra & Dewi', 'Pangkalpinang'],
    ]);

    $rows = iterator_to_array((new XlsxReader($path))->rows());

    expect($rows[0])->toBe(['nim', 'nama', 'kabupaten'])
        // A leading zero survives: written as text, not as the number 10123456.
        ->and($rows[1][0])->toBe('010123456')
        // Quotes and ampersands are XML-escaped on write and unescaped on read.
        ->and($rows[2][1])->toBe('Budi "Si Kecil" Santoso')
        ->and($rows[3][1])->toBe('Citra & Dewi');

    unlink($path);
});

it('imports an xlsx file end to end', function () {
    $path = tempnam(sys_get_temp_dir(), 'import_').'.xlsx';

    // Headings and values as the real export writes them: "Kabko" for the
    // regency, "Sub Katagori" quoted and comma-suffixed.
    (new XlsxWriter)->write($path, ['NIM', 'Nama Mahasiswa', 'Kabko', 'Pos', 'Sub Katagori'], [
        ['IMP0000080', 'Gina Maharani', '88076/KAB. BANGKA', 'KEC. MENDO BARAT', "'Maba Belum Reg MK Semester Lalu',"],
        ['IMP0000081', 'Hendra Wijaya', '88078 | KAB. BANGKA TENGAH', 'KEC. PANGKALAN BARU', "'Ongoing Tidak Registrasi Semeter Lalu',"],
    ]);

    $import = runImport(new UploadedFile($path, 'mahasiswa.xlsx', null, null, true));

    expect($import->status)->toBe(StudentImport::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(2)
        ->and(Student::where('nim', 'IMP0000080')->value('nama'))->toBe('Gina Maharani')
        ->and(Student::where('nim', 'IMP0000080')->value('kategori_masalah'))
        ->toBe(StudentCondition::MabaBelumRegMk)
        ->and(Student::where('nim', 'IMP0000081')->value('kategori_masalah'))
        ->toBe(StudentCondition::OngoingTidakRegistrasi);

    // Both code separators are stripped, so one regency is one filter value.
    expect(Student::where('nim', 'IMP0000080')->value('kabupaten'))->toBe('Kab. Bangka')
        ->and(Student::where('nim', 'IMP0000081')->value('kabupaten'))->toBe('Kab. Bangka Tengah')
        // "Pos" is the kecamatan column despite its name.
        ->and(Student::where('nim', 'IMP0000080')->value('kecamatan'))->toBe('Kec. Mendo Barat');
});

it('reads the real workbook’s second sheet, not its dashboard', function () {
    // Mirrors the supplied file: a summary tab first, the data behind it.
    $path = tempnam(sys_get_temp_dir(), 'twotab_').'.xlsx';

    (new XlsxWriter)->write($path, ['NIM', 'Nama Mahasiswa', 'Kabko'], [
        ['IMP0000090', 'Mahasiswa Sheet', '88076/KAB. BANGKA'],
    ]);

    $service = app(StudentImportService::class);
    $import = $service->preview(
        new UploadedFile($path, 'dua-tab.xlsx', null, null, true),
        User::first(),
    )['import'];

    // A single-sheet file still resolves to that sheet rather than to null.
    expect($import->sheet)->not->toBeNull();

    $service->import($import);

    expect(Student::where('nim', 'IMP0000090')->exists())->toBeTrue();
});

it('treats the file’s blank markers as empty, not as data', function () {
    runImport(csvUpload("nim,nama,kabko,email,pokjar\nIMP0000100,Pakai Garis Miring,/,0,Tidak dikenal\n"));

    $student = Student::where('nim', 'IMP0000100')->first();

    // "/", "0" and "Tidak dikenal" are placeholders in this export. Storing
    // them would fill every filter dropdown with junk.
    expect($student->kabupaten)->toBeNull()
        ->and($student->email)->toBeNull()
        ->and($student->pokjar)->toBeNull();
});

it('keeps column positions when a cell in the middle is empty', function () {
    $path = tempnam(sys_get_temp_dir(), 'gaps_').'.xlsx';

    (new XlsxWriter)->write($path, ['nim', 'nama', 'kabupaten'], [
        ['IMP0000090', '', 'Belitung'],
    ]);

    $import = runImport(new UploadedFile($path, 'gaps.xlsx', null, null, true));
    $student = Student::where('nim', 'IMP0000090')->first();

    // The blank name must not shift "Belitung" left into the nama column.
    expect($import->imported_count)->toBe(1)
        ->and($student->nama)->toBeNull()
        ->and($student->kabupaten)->toBe('Belitung');
});

/* -----------------------------------------------------------------
 | Scale
 * ----------------------------------------------------------------- */

it('imports a large file in one pass', function () {
    $lines = ['nim,nama,kabupaten'];

    for ($i = 0; $i < 2000; $i++) {
        $lines[] = 'BULK'.str_pad((string) $i, 6, '0', STR_PAD_LEFT).",Mahasiswa {$i},Bangka";
    }

    $import = runImport(csvUpload(implode("\n", $lines), 'besar.csv'));

    expect($import->status)->toBe(StudentImport::STATUS_COMPLETED)
        ->and($import->total_rows)->toBe(2000)
        ->and($import->imported_count)->toBe(2000)
        ->and(Student::where('nim', 'like', 'BULK%')->count())->toBe(2000);
});
