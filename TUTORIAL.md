# Tutorial

Panduan menjalankan aplikasi di komputer sendiri, menaikkannya ke hosting, dan
memahami alur kerja di dalamnya.

Aplikasi ini adalah **CRM sosial media**: menjadwalkan konten, menarik komentar
dari akun yang terhubung, menilainya dengan AI, lalu mengubah orang yang
berinteraksi menjadi kontak, agent, atau calon mahasiswa.

---

## Daftar Isi

- [Bagian 1 — Menjalankan di komputer sendiri](#bagian-1--menjalankan-di-komputer-sendiri)
- [Bagian 2 — Menaikkan ke hosting](#bagian-2--menaikkan-ke-hosting)
- [Bagian 3 — Alur website, dari login sampai agent](#bagian-3--alur-website-dari-login-sampai-agent)
- [Bagian 4 — Daftar perintah](#bagian-4--daftar-perintah)
- [Bagian 5 — Kalau ada yang salah](#bagian-5--kalau-ada-yang-salah)

---

# Bagian 1 — Menjalankan di komputer sendiri

## Yang harus ada

| | Versi | Catatan |
|---|---|---|
| PHP | 8.2 atau lebih baru | Laragon sudah membawanya |
| MySQL | 5.7 / 8.x | Dinyalakan lewat Laragon |
| Composer | 2.x | |
| Node.js | 20 atau lebih baru | **Hanya untuk mengubah tampilan**, tidak untuk menjalankan |

Node **tidak** diperlukan untuk menjalankan aplikasi. Aset CSS/JS sudah
dikompilasi ke `public/build`, dan aplikasi memakai berkas itu.

## Pemasangan pertama kali

Cukup sekali, saat pertama menyiapkan di komputer baru.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Buka `.env`, sesuaikan koneksi database:

```ini
DB_DATABASE=autopost
DB_USERNAME=root
DB_PASSWORD=

APP_URL=http://127.0.0.1:5566
```

Buat databasenya di phpMyAdmin (namanya harus sama dengan `DB_DATABASE`), lalu:

```bash
php artisan migrate --seed          # tabel + role, hak akses, 38 provinsi, akun contoh
php artisan storage:link            # agar foto tersimpan bisa diakses browser
npm install && npm run build        # sekali saja, kecuali tampilan diubah
```

## Menjalankan sehari-hari

Nyalakan **MySQL** lewat Laragon, lalu buka tiga terminal:

```bash
php artisan serve --port=5566        # aplikasinya
php artisan schedule:work            # otomasi: klasifikasi AI, sinkron, dll
php artisan queue:listen --tries=1   # pekerjaan latar
```

Atau satu perintah untuk ketiganya:

```bash
composer run dev
```

Berhenti dengan `Ctrl+C` sekali — ketiganya ikut mati.

Buka **http://127.0.0.1:5566**

> **`schedule:work` itu penting.** Tanpa itu aplikasi tetap terbuka, tapi diam:
> tidak ada komentar yang ditarik, tidak ada yang dinilai AI, dan konten
> terjadwal tidak pernah terbit. Ia adalah pengganti cron selama development.

## Kalau mau mengubah tampilan (CSS/JS)

Baru di sini Node diperlukan:

```bash
npm run dev       # tampilan langsung berubah saat berkas disimpan
```

Setelah selesai, **wajib**:

```bash
npm run build     # kompilasi ulang agar berlaku tanpa npm run dev
```

> **Jebakan:** `npm run dev` membuat berkas `public/hot`. Selama berkas itu ada,
> aplikasi memuat aset dari server Vite. Kalau Vite mati mendadak, berkas itu
> tertinggal dan **tampilan jadi polos tanpa CSS**. Solusinya: hapus
> `public/hot`, lalu `npm run build`.

---

# Bagian 2 — Menaikkan ke hosting

## Persiapan di komputer sendiri

Bangun aset dulu, karena kebanyakan shared hosting tidak punya Node:

```bash
npm run build
```

> **Penting.** `.gitignore` mengabaikan `public/build`. Kalau deploy lewat Git
> dan foldernya tidak ikut, **website akan tampil tanpa CSS sama sekali**.
> Dua pilihan:
>
> 1. Unggah folder `public/build` secara manual lewat FTP/File Manager, **atau**
> 2. Ikutkan ke repo — hapus baris `/public/build` dari `.gitignore`, lalu commit.
>
> Pilihan 2 lebih aman untuk shared hosting.

## Unggah

Unggah seluruh folder proyek **kecuali**:

```
node_modules/     vendor/     .env     storage/logs/*
```

Lalu di server:

```bash
composer install --no-dev --optimize-autoloader
```

## Struktur folder di shared hosting

Isi `public_html` harus menunjuk ke folder `public` milik Laravel, bukan ke akar
proyek. Cara yang paling aman:

```
/home/user/
├── laravel/          ← seluruh proyek diletakkan di sini (di luar public_html)
└── public_html/      ← isi folder public/ disalin ke sini
```

Setelah menyalin isi `public/` ke `public_html`, buka `public_html/index.php` dan
perbaiki dua barisnya:

```php
require __DIR__.'/../laravel/vendor/autoload.php';
$app = require_once __DIR__.'/../laravel/bootstrap/app.php';
```

## Konfigurasi `.env` di server

```ini
APP_ENV=production
APP_DEBUG=false                      # WAJIB false — kalau true, pesan error membocorkan isi .env
APP_URL=https://domain-anda.com

DB_DATABASE=nama_db_hosting
DB_USERNAME=user_db
DB_PASSWORD=sandi_db

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

GROQ_API_KEY=gsk_...                 # ganti dengan kunci baru, jangan pakai yang lama
CRM_AI_DRIVER=groq
```

Lalu:

```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Izin folder

```bash
chmod -R 775 storage bootstrap/cache
```

## Cron — satu baris, dan ini yang membuat semuanya hidup

Di cPanel → **Cron Jobs**, tambahkan **satu** entri, berjalan **setiap menit**:

```
* * * * * cd /home/user/laravel && php artisan schedule:run >> /dev/null 2>&1
```

Cukup satu. Laravel yang memutuskan tugas mana yang jatuh tempo tiap menit:

| Tugas | Jadwal |
|---|---|
| Denyut nadi penjadwal | tiap menit |
| Terbitkan konten terjadwal | tiap menit |
| Snapshot metrik akun | tiap menit |
| Klasifikasi komentar dengan AI | tiap 15 menit |
| Insight postingan · cocokkan kontak · simpan gambar · bersihkan jadwal | tiap jam |
| Tarik komentar Instagram | tiap 5 jam |
| Perpanjang token Instagram | tiap hari 02:00 |

> **Cara memastikan cron benar-benar jalan:** buka Dashboard sebagai Super Admin.
> Ada garis status di paling atas. Kalau tertulis *"Otomasi berjalan normal"*
> berarti aman. Kalau merah *"Otomasi TIDAK berjalan"*, cron-nya belum jalan.

## Pekerja antrean (queue)

Kalau hosting mengizinkan proses latar, jalankan Supervisor:

```
php artisan queue:work --sleep=3 --tries=3
```

Kalau tidak bisa (umum di shared hosting), tambahkan cron kedua:

```
* * * * * cd /home/user/laravel && php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

Aplikasi tetap berfungsi tanpa ini — klasifikasi AI berjalan lewat penjadwal,
bukan antrean.

## Setelah rilis

```bash
php artisan crm:examples --clear     # hapus data contoh
php artisan regions:import wilayah.csv   # data wilayah lengkap (format: kode,nama)
```

---

# Bagian 3 — Alur website, dari login sampai agent

## 1. Masuk

Buka alamat aplikasi, isi email dan kata sandi. Akun contoh (kata sandi semuanya
`password`):

| Email | Peran | Melihat apa |
|---|---|---|
| `admin@example.com` | Super Admin | Semuanya |
| `direktur@example.com` | Direktur | Semua angka, tidak mengubah apa pun |
| `manager@example.com` | Manager | Inbox, pembagian tugas, beban petugas |
| `pic1@example.com` | PIC | Inbox, membalas |
| `operator1@example.com` | Operator | Inbox, data kontak, mengangkat agent |
| `creative1@example.com` | Tim Creative | Membuat konten |
| `curator1@example.com` | Curator | Menyetujui konten |
| `verifikator1@example.com` | Verifikator | Pengecekan akhir |

Menu di samping kiri **menyesuaikan peran**. Yang tidak boleh diakses tidak
ditampilkan sama sekali — bukan ditampilkan lalu ditolak.

## 2. Dashboard

Halaman pertama setelah masuk. Dibaca dari atas ke bawah:

**Status otomasi** (hanya Super Admin & Direktur) — satu baris tipis kalau sehat.
Kalau memerah, semua angka di bawahnya sudah basi dan harus dibaca begitu.

**Angka konten** — draft, menunggu approval, terjadwal, terbit, dibandingkan
dengan periode sebelumnya.

**Interaksi & Sentimen** (peran yang menangani inbox) — empat kartu:

| Kartu | Artinya |
|---|---|
| Lewat batas waktu | Komentar mendesak yang belum dibalas > 2 jam. **Ini yang harus dikerjakan hari ini.** |
| Mendesak belum selesai | Semua yang ditandai mendesak dan masih terbuka |
| Perlu dibalas | Pertanyaan dan keluhan yang menunggu |
| Ketepatan respons | Berapa persen dibalas dalam target waktu, 30 hari terakhir |

Di bawahnya: grafik tren sentimen 14 hari, antrean mendesak (**terlama dulu** —
komentar marah yang baru masuk bukan yang paling berisiko terlupakan), dan beban
tiap petugas.

## 3. Alur konten

```
Tim Creative  →  Curator  →  Verifikator  →  otomatis terbit
   buat draft    setujui &     cek akhir      sesuai jadwal
                 jadwalkan
```

**Konten** → *Buat Konten*. Isi judul, caption, unggah foto/video, pilih akun
tujuan, lalu **Kirim untuk Approval**.

**Approval** (Curator) — antrean konten menunggu. Bisa **Setujui**, **Tolak**,
atau **Minta Revisi** dengan catatan. Saat menyetujui, Curator sekaligus
menentukan waktu terbit.

**Verifikasi** (Verifikator) — pengecekan terakhir sebelum tayang.

**Kalender** — semua jadwal dalam tampilan bulanan. Bisa digeser untuk mengubah
jadwal, dan bisa diberi catatan atau pengingat.

Konten yang sudah lolos akan **terbit sendiri** saat waktunya tiba — asalkan cron
berjalan.

> **Pengaman:** konten yang terlambat lebih dari 24 jam dari jadwalnya **tidak**
> diterbitkan otomatis. Ia ditandai gagal agar ditinjau manusia dulu. Ini
> mencegah kejadian: cron mati seminggu, lalu hidup lagi, dan semua tunggakan
> terbit sekaligus seolah masih relevan.

## 4. Inbox Interaksi — inti dari CRM

Semua komentar dan pesan dari semua kanal, sudah dinilai dan terpilah.

### Tab yang tersedia

| Tab | Isinya |
|---|---|
| **Negatif Urgent** | Komentar menyerang nama baik institusi. Target balas 2 jam. |
| **Pertanyaan** | Menanyakan pendaftaran, biaya, syarat |
| **Perlu Dibalas** | Semua yang menunggu jawaban |
| **Tugas Saya** | Yang ditugaskan ke akun Anda |
| **Semua Interaksi** | Tanpa filter |
| **Catat Manual** | Formulir untuk kanal tanpa API |
| **Akurasi AI** | Seberapa sering AI dikoreksi manusia |

Angka merah di menu samping = jumlah komentar mendesak yang belum ditangani.

### Membaca satu baris

```
@warga_marah  [Instagram] [Komentar]  · 5 jam lalu
"kampus ini penipuan, ijazahnya gak diakui..."
[Mendesak] [Negatif] [Menjelekkan]                     Baru · Telat 3 jam
```

Garis merah di tepi kiri menandai yang mendesak.

### Mengerjakan banyak sekaligus

Centang beberapa baris, lalu bilah aksi muncul di bawah:

- **Tugaskan** ke petugas tertentu (hanya Manager)
- **Selesai** — untuk pujian yang tidak perlu dibalas
- **Abaikan** — untuk spam
- **Nilai ulang** — minta AI menilai kembali

Ini penting karena sebagian besar komentar adalah pujian singkat. Menutupnya
satu per satu tidak realistis.

### Halaman detail

Klik satu baris untuk membuka:

- **Isi pesannya** dan postingan asalnya
- **Hasil klasifikasi** — sentimen, jenis, potensi jadi mahasiswa, alasan AI, dan
  tingkat keyakinannya
- **Tombol koreksi** — kalau AI salah, perbaiki di sini. Jawaban AI **tetap
  disimpan** di samping koreksi Anda; pasangan itulah yang dipakai mengukur
  akurasi.
- **Riwayat follow-up** — semua tindak lanjut, berurutan
- **Identitas** — siapa orangnya, nomor WA, tombol chat langsung
- **Penanganan** — ubah status, tugaskan ke orang lain

### Mencatat follow-up

Di bagian bawah halaman detail:

| Isian | Contoh |
|---|---|
| Tindakan | Dibalas / Ditelepon / Chat WhatsApp / Tidak Merespon |
| Lewat | WhatsApp / DM / Telepon |
| Isi tanggapan | Apa yang disampaikan |
| Hasil | Positif / Netral / Negatif |
| Tindak lanjut berikutnya | Tanggal pengingat |

Satu orang bisa di-follow-up berkali-kali; semuanya tercatat berurutan lengkap
dengan **jabatan petugas saat itu**, jadi catatan tidak berubah ketika seseorang
naik jabatan.

### Catat manual

Untuk kanal yang tidak bisa ditarik otomatis:

- **DM TikTok** — TikTok tidak menyediakan API untuk membaca DM sama sekali
- **WhatsApp** — perlu akun bisnis yang disetujui

Isi kanal, username **atau** nomor WA, dan salin pesannya apa adanya (jangan
diringkas — teks itu yang dinilai AI). Setelah tersimpan, diperlakukan persis
sama dengan komentar yang ditarik otomatis.

## 5. Bagaimana AI menilai

Tiga lapis, dan **sebagian besar komentar tidak pernah menyentuh AI**:

1. **Kamus bahasa Indonesia** — 293 kata bermuatan makna plus 57 pemetaan slang,
   semuanya di `app/Services/AI/Lexicon.php`. Gratis, instan, jalan tanpa
   internet. Menormalkan singkatan (`bgt` → `banget`) dan huruf berulang
   (`kerennnn` → `keren`).
2. **AI, hanya bila kamus ragu** — dikirim berkelompok, bukan satu per satu.
3. **Ingatan** — hasil disimpan berdasarkan isi teks. "Keren kak" yang muncul 400
   kali hanya dinilai satu kali.

**Dan satu aturan yang mengalahkan semuanya:** daftar kata mendesak
(*penipuan, ijazah palsu, viralkan, pungli*) diperiksa **paling akhir**, setelah
AI selesai. Kalau salah satunya muncul, komentar ditandai mendesak apa pun kata
AI — karena AI bisa mati, kehabisan kuota, atau salah.

Daftar itu ada di `config/crm.php` dan bisa ditambah tanpa mengerti pemrograman.

## 6. Database Kontak (UID)

Satu baris = **satu orang**, bukan satu akun. Orang yang sama bisa punya
Instagram, TikTok, dan nomor WhatsApp — semuanya menunjuk ke satu UID
(`UT-000142`).

Ini yang membuat sisanya mungkin. Tanpa UID, "Budi yang komentar di IG" dan
"Budi yang chat di TikTok" adalah dua orang asing.

**Tugas Operator** di halaman detail kontak:

- **Nama asli** — nama akun sosmed biasanya bukan nama sebenarnya
- **Nomor WhatsApp** — boleh ditulis `0812…`, `+62812…`, atau `62812…`, otomatis
  diseragamkan supaya tidak jadi kontak ganda
- **Wilayah** — bertingkat: Provinsi → Kabupaten/Kota → Kecamatan → Desa
- **Potensi jadi mahasiswa** — geser 0–100
- **Catatan**

> Nomor WhatsApp yang ditulis seseorang di kolom komentar publik **diambil
> otomatis** dan langsung jadi tombol chat.

## 7. Mengangkat Agent

Orang yang konsisten positif dan aktif membantu bisa diangkat jadi agent.

Buka detail kontaknya → tombol **Jadikan Agent**.

Sistem akan **menolak** kalau nama asli atau nomor WhatsApp belum diisi. Ini
disengaja: mengejar agent yang tidak punya nama dan nomor jauh lebih mahal
daripada memblokirnya sekarang.

Setelah diangkat, agent mendapat kode sendiri (`AGT-00001`) dan masuk ke menu
**Database → Agent**, lengkap dengan wilayah dan seluruh akun sosmednya.

## 8. Monitoring & Analytics

**Monitoring** — performa akun terhubung: follower, like, komentar, reach, serta
seluruh postingan yang bisa dicari dan diurutkan. Klik satu postingan untuk
melihat pertumbuhan angkanya per jam / hari / minggu, beserta komentarnya.

**Analytics** — perbandingan antar periode dan jam terbaik untuk memposting.

**UT Monitoring Account** — unggah data akun dalam jumlah besar berbentuk JSON,
lalu divalidasi dan dianalisa.

## 9. Pengaturan sistem

**Akun Sosmed** — hubungkan akun Instagram dengan access token. Tombol
**Verifikasi** menguji tokennya sekaligus menarik ulang foto profil.

**Pengguna & Role** — kelola pengguna dan perannya.

**Log Aktivitas** — jejak audit: siapa melakukan apa, kapan, dari IP mana.

---

# Bagian 4 — Daftar perintah

## Menjalankan

```bash
php artisan serve --port=5566          # aplikasi
php artisan schedule:work              # otomasi (pengganti cron saat development)
php artisan queue:listen --tries=1     # pekerjaan latar
composer run dev                       # ketiganya sekaligus
```

## Data contoh

```bash
php artisan crm:examples               # 2 agent + 6 interaksi contoh
php artisan crm:examples --classify    # sekaligus dinilai AI
php artisan crm:examples --clear       # hapus semua data contoh
```

## AI

```bash
php artisan interactions:classify --check      # uji koneksi ke penyedia AI
php artisan interactions:classify --test="teks di sini"   # coba satu kalimat
php artisan interactions:classify              # nilai yang belum dinilai
```

## Sinkronisasi

```bash
php artisan accounts:sync-metrics      # follower & jumlah postingan
php artisan accounts:sync-insights     # insight tiap postingan
php artisan accounts:sync-comments     # tarik komentar publik
php artisan contacts:resolve           # cocokkan interaksi ke kontak
php artisan media:cache                # simpan gambar sebelum tautannya mati
```

## Pemeliharaan

```bash
php artisan schedules:prune --dry-run  # lihat jadwal bermasalah tanpa mengubah
php artisan schedules:prune            # bersihkan
php artisan regions:import wilayah.csv # data wilayah lengkap
```

---

# Bagian 5 — Kalau ada yang salah

## Tampilan polos, tanpa CSS

Berkas `public/hot` tertinggal dari Vite yang mati mendadak.

```bash
rm public/hot
npm run build
```

Di hosting: folder `public/build` belum terunggah. Lihat [Bagian 2](#persiapan-di-komputer-sendiri).

## Foto profil dan thumbnail tidak muncul

Tautan gambar Instagram **ditandatangani dan kedaluwarsa** sekitar dua minggu.
Aplikasi menyimpan salinannya sendiri saat sinkron, tapi yang sudah terlanjur
mati tidak bisa dipulihkan.

```bash
php artisan accounts:sync-metrics      # foto profil akun
php artisan accounts:sync-insights     # thumbnail postingan
php artisan accounts:sync-comments     # avatar komentator
```

Kalau foto profil akun berganti di Instagram, tekan **Verifikasi** di halaman
Akun Sosmed — itu memaksa unduh ulang.

## Komentar tidak masuk / tidak dinilai

Cek berurutan:

```bash
php artisan interactions:classify --check   # penyedia AI hidup?
php artisan accounts:sync-comments          # tarik manual, lihat pesannya
```

Lalu buka Dashboard sebagai Super Admin — kalau statusnya merah, cron-nya yang
belum jalan.

## Konten terjadwal tidak terbit

1. Cron sudah dipasang? Lihat garis status di Dashboard.
2. Token Instagram masih berlaku? Halaman Akun Sosmed → **Verifikasi**.
3. Sudah terlambat lebih dari 24 jam? Kalau ya, sengaja tidak diterbitkan —
   statusnya jadi "Gagal" dengan alasannya. Jadwalkan ulang.

```bash
php artisan schedules:prune --dry-run
```

## Klasifikasi AI berhenti bekerja

Katalog model penyedia AI bisa berubah sewaktu-waktu.

```bash
php artisan interactions:classify --check
```

Kalau modelnya sudah tidak ada, ganti `GROQ_MODEL` di `.env`. Sistem tetap
berjalan memakai kamus offline — hanya kasus sulit seperti sarkasme yang jadi
kurang akurat.

## Lupa kata sandi

```bash
php artisan tinker
>>> $u = App\Models\User::where('email','admin@example.com')->first();
>>> $u->password = Hash::make('sandibaru'); $u->save();
```

## Batasan yang perlu diketahui

| Hal | Keadaan |
|---|---|
| Komentar Instagram | Lewat pembaca publik tidak resmi — bisa berubah atau berhenti sewaktu-waktu |
| DM TikTok | Tidak ada API sama sekali. Input manual adalah satu-satunya cara |
| WhatsApp | Perlu akun bisnis yang disetujui |
| Akurasi AI | Belum teruji pada komentar negatif dalam jumlah besar. Pakai dulu 2–4 minggu, lalu nilai dari halaman Akurasi AI |
