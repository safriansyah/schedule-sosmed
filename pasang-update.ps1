<#
    Dijalankan DI KOMPUTER SERVER, setelah ZIP dari kirim-update.ps1 diekstrak.

    KENAPA SKRIP INI ADA

    Menyalin berkas saja tidak cukup. Berkas baru sering membawa kolom database
    baru, dan tanpa `php artisan migrate` aplikasi berjalan di atas skema lama.
    Gejalanya menyesatkan -- error menyebut nama kolom, bukan migrasi yang
    belum dijalankan -- dan pada perintah backfill ia baru muncul SETELAH
    ratusan panggilan API terbuang.

    Skrip ini menjalankan urutan yang benar, dan berhenti kalau ada yang salah
    alih-alih melanjutkan dengan setengah pemasangan.

    PEMAKAIAN

        .\pasang-update.ps1

    Tutup dulu jendela `composer run dev:lan` sebelum menjalankannya.
#>

$ErrorActionPreference = "Stop"

Set-Location $PSScriptRoot

function Langkah($nomor, $judul) {
    Write-Host ""
    Write-Host "  [$nomor] $judul" -ForegroundColor Cyan
}

function Jalankan($perintah) {
    Write-Host "      $perintah" -ForegroundColor DarkGray
    Invoke-Expression $perintah

    if ($LASTEXITCODE -ne 0) {
        Write-Host ""
        Write-Host "  GAGAL di: $perintah" -ForegroundColor Red
        Write-Host "  Pemasangan dihentikan. Perbaiki dulu, lalu jalankan ulang skrip ini." -ForegroundColor Red
        Write-Host ""
        exit 1
    }
}

Write-Host ""
Write-Host "  Memasang pembaruan UT Sosmed Monitoring" -ForegroundColor Cyan
Write-Host "  Folder: $PSScriptRoot"

# .env dan storage\ sengaja tidak ikut di dalam ZIP. Kalau salah satunya hilang,
# yang terjadi bukan aplikasi rusak setengah -- melainkan aplikasi tidak bisa
# jalan sama sekali, jadi lebih baik ketahuan di sini.
if (-not (Test-Path ".env")) {
    Write-Host ""
    Write-Host "  .env tidak ada di folder ini." -ForegroundColor Red
    Write-Host "  Berkas itu milik komputer ini (sandi database & token) dan memang" -ForegroundColor Red
    Write-Host "  tidak ikut di dalam ZIP. Kembalikan dulu dari cadangan." -ForegroundColor Red
    Write-Host ""
    exit 1
}

if (-not (Test-Path "database\migrations")) {
    Write-Host ""
    Write-Host "  database\migrations tidak ada -- ZIP-nya tidak lengkap." -ForegroundColor Red
    Write-Host "  Kemas ulang di komputer asal dengan .\kirim-update.ps1" -ForegroundColor Red
    Write-Host ""
    exit 1
}

Langkah 1 "Dependensi PHP"
if (Test-Path "vendor") {
    Write-Host "      vendor\ sudah ada -- dilewati" -ForegroundColor DarkGray
    Write-Host "      (jalankan 'composer install' manual kalau composer.json berubah)" -ForegroundColor DarkGray
} else {
    Jalankan "composer install --no-dev --optimize-autoloader"
}

Langkah 2 "Tautan storage"
if (Test-Path "public\storage") {
    Write-Host "      sudah ada -- dilewati" -ForegroundColor DarkGray
} else {
    Jalankan "php artisan storage:link"
}

Langkah 3 "Migrasi database (kolom baru)"
Jalankan "php artisan migrate --force"

Langkah 4 "Role & izin"
# Aman diulang: hanya menulis role dan izin. BUKAN `db:seed` tanpa --class,
# yang akan ikut memasukkan data contoh mahasiswa dan tugas.
Jalankan "php artisan db:seed --class=RolePermissionSeeder --force"

Langkah 5 "Bersihkan cache"
Jalankan "php artisan config:clear"
Jalankan "php artisan view:clear"
Jalankan "php artisan route:clear"

Langkah 6 "Pemeriksaan"
Write-Host ""
php artisan migrate:status | Select-Object -Last 6
Write-Host ""
php artisan monitoring:diagnose

Write-Host ""
Write-Host "  Pemasangan selesai." -ForegroundColor Green
Write-Host ""
Write-Host "  Jalankan lagi servernya:" -ForegroundColor Cyan
Write-Host "      composer run dev:lan"
Write-Host ""
