<#
    Mengemas aplikasi jadi satu ZIP untuk dipindahkan ke komputer server.

    KENAPA SKRIP INI ADA

    Cara sebelumnya adalah memilih folder yang "terlihat berubah" -- biasanya
    app, resources, routes, tests. Itu gagal, karena hampir setiap perubahan
    ikut menyentuh database/migrations (kolom baru), database/seeders (role
    baru) dan config (pengaturan baru). Yang paling sering ketinggalan adalah
    migrasi, dan gejalanya menyesatkan:

        SQLSTATE[42S22]: Column not found: 1054 Unknown column 'media_cursor'

    Jadi skrip ini tidak memilih apa yang DIBAWA. Ia membawa semuanya, dan
    hanya menyingkirkan yang memang MILIK komputer tujuan:

        .env          kunci aplikasi & sandi database komputer sana
        storage/      unggahan, thumbnail, dan log komputer sana
        vendor/       hasil composer install, dibangun di sana
        node_modules/ hasil npm install, dibangun di sana
        .git/         besar dan tidak diperlukan untuk menjalankan aplikasi

    public/build IKUT dibawa (CSS & JS hasil kompilasi), supaya komputer server
    tidak perlu menjalankan npm sama sekali.

    PEMAKAIAN

        .\kirim-update.ps1
        .\kirim-update.ps1 -Tujuan D:\

    Hasilnya satu file ZIP. Di komputer server: ekstrak MENIMPA folder aplikasi,
    lalu jalankan .\pasang-update.ps1 yang ikut di dalam ZIP.
#>

param(
    # Di mana file ZIP diletakkan. Default: satu tingkat di atas folder aplikasi.
    [string]$Tujuan = ""
)

$ErrorActionPreference = "Stop"

$app = $PSScriptRoot
$nama = Split-Path $app -Leaf

if ([string]::IsNullOrWhiteSpace($Tujuan)) {
    $Tujuan = Split-Path $app -Parent
}

if (-not (Test-Path $Tujuan)) {
    Write-Host "  Folder tujuan tidak ada: $Tujuan" -ForegroundColor Red
    exit 1
}

$stempel = Get-Date -Format "yyyyMMdd-HHmm"
$zip = Join-Path $Tujuan "$nama-update-$stempel.zip"
$staging = Join-Path $env:TEMP "kirim-update-$stempel"

Write-Host ""
Write-Host "  Mengemas $nama" -ForegroundColor Cyan
Write-Host "  Sumber : $app"
Write-Host "  Hasil  : $zip"
Write-Host ""

# Disalin ke folder sementara dulu, bukan di-zip langsung dari sumber.
# Compress-Archive tidak punya opsi pengecualian, dan public\storage adalah
# symbolic link ke storage\app\public -- kalau ikut terbawa, ia menimpa
# tautan milik komputer tujuan dan gambar berhenti tampil di sana.
#
# /XD dan /XF mencocokkan nama di kedalaman mana pun, jadi "storage" di sini
# menyingkirkan storage\ di root DAN public\storage sekaligus.
Write-Host "  Menyalin berkas..." -ForegroundColor DarkGray

$robo = robocopy $app $staging /E /NFL /NDL /NJH /NJS /NC /NS /NP `
    /XD vendor node_modules storage .git .idea .vscode `
    /XF .env "*.zip" "*.log"

# robocopy memakai kode keluar 0-7 untuk sukses; 8 ke atas baru kegagalan.
if ($LASTEXITCODE -ge 8) {
    Write-Host "  Gagal menyalin (robocopy $LASTEXITCODE)." -ForegroundColor Red
    exit 1
}

if (-not (Test-Path (Join-Path $staging "database\migrations"))) {
    # Justru berkas inilah yang dulu ketinggalan, jadi ketiadaannya harus
    # menggagalkan pengemasan, bukan menghasilkan ZIP yang diam-diam rusak.
    Write-Host "  database\migrations tidak ikut tersalin -- dibatalkan." -ForegroundColor Red
    Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
    exit 1
}

Write-Host "  Memampatkan..." -ForegroundColor DarkGray

if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path (Join-Path $staging "*") -DestinationPath $zip -CompressionLevel Optimal

$berkas = (Get-ChildItem $staging -Recurse -File | Measure-Object).Count
$mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)

Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "  Selesai -- $berkas berkas, $mb MB" -ForegroundColor Green
Write-Host "  $zip"
Write-Host ""
Write-Host "  DI KOMPUTER SERVER:" -ForegroundColor Cyan
Write-Host "    1. Tutup jendela 'composer run dev:lan'"
Write-Host "    2. Ekstrak ZIP ini MENIMPA folder aplikasi"
Write-Host "    3. Jalankan:  .\pasang-update.ps1"
Write-Host ""
Write-Host "  .env dan storage\ di sana TIDAK tertimpa -- keduanya sengaja" -ForegroundColor DarkGray
Write-Host "  tidak ikut di dalam ZIP ini." -ForegroundColor DarkGray
Write-Host ""

# robocopy memakai kode keluar 1 untuk "berhasil, ada berkas disalin", dan
# nilai itu masih menempel di $LASTEXITCODE sampai skrip berakhir. Tanpa baris
# ini, pengemasan yang sukses dilaporkan sebagai gagal oleh pemanggilnya.
exit 0
