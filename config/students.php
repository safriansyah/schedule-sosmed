<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Column mapping
    |--------------------------------------------------------------------------
    |
    | THIS IS THE FILE TO EDIT WHEN THE REAL SPREADSHEET ARRIVES.
    |
    | Each key is a column on `students`; each value lists the spreadsheet
    | headers that should feed it. Matching is case-insensitive and ignores
    | spaces, underscores and punctuation, so "No. HP", "no_hp" and "NoHp" all
    | hit the same alias.
    |
    | Adding a heading here is the whole job — no migration, no code change.
    | Anything not listed is still imported: it is kept verbatim in the
    | `extra` JSON column, so a column nobody predicted is never lost.
    |
    */

    'mapping' => [
        // Identity
        'nim' => ['nim', 'no mahasiswa', 'nomor mahasiswa', 'npm'],
        'nama' => ['nama mahasiswa', 'nama', 'nama lengkap'],
        'nac' => ['nac', 'no nac', 'nomor nac', 'kode nac'],

        // Contact
        'email' => ['email', 'e mail', 'alamat email'],
        'email_alternatif' => ['email alternatif', 'email alternatif 2', 'email cadangan'],
        'no_hp' => ['hp1', 'hp 1', 'no hp', 'nohp', 'hp', 'whatsapp', 'wa', 'no wa'],
        'hp2' => ['hp2', 'hp 2', 'no hp 2'],
        'telp' => ['telp', 'telepon', 'no telepon'],
        'alamat' => ['alamat mahasiswa', 'alamat'],

        // Academic
        'fakultas' => ['fakultas'],
        'program_studi' => ['prodi', 'program studi', 'jurusan'],
        'sipas' => ['sipas'],
        'semester_terakhir' => ['masa', 'semester terakhir', 'masa registrasi', 'periode'],
        'mri' => ['mri'],
        'mra' => ['mra'],

        // Region, widest first. The export currently carries no province and no
        // kelurahan — its rows are almost all Bangka Belitung, so the province
        // was left implied. Both are listed anyway: the day a file arrives
        // with them, the region filters and the region-based hand-out fill in
        // without a migration or a code change.
        'provinsi' => ['provinsi', 'prov', 'propinsi'],
        // "Kabko" is the institution's own column name.
        'kabupaten' => ['kabko', 'kabupaten', 'kabupaten kota', 'kab', 'kota', 'kab kota'],
        // "Pos" is the institution's column name for the kecamatan — despite
        // the name it holds "KEC. TANJUNG PANDAN", not a postal code.
        'kecamatan' => ['pos', 'kecamatan', 'kec'],
        'kelurahan' => ['kelurahan', 'desa', 'desa kelurahan', 'kel'],
        'pokjar' => ['pokjar', 'kelompok belajar', 'salut'],
        'wilayah_ujian' => ['wilayah ujian', 'wil ujian'],

        // Registration state
        'status_dp' => ['status dp', 'statusdp'],
        'status_registrasi' => ['status registrasi', 'registrasi'],
        'status_pembayaran' => ['status pembayaran', 'pembayaran', 'status bayar'],
        'status_billing_nac' => ['status billing nac', 'status billing', 'billing'],
        'status_registrasi_matkul' => ['status registrasi matkul', 'registrasi matkul', 'status matkul'],

        // Classification. Sub Katagori carries the real condition vocabulary;
        // Katagori splits the list into Maba vs Ongoing.
        'kategori_masalah' => ['sub katagori', 'sub kategori', 'kondisi', 'kategori masalah', 'masalah'],
        'segmen' => ['katagori', 'kategori', 'segmen'],

        // Handling
        'petugas_nama' => ['petugas nama', 'nama petugas', 'petugas'],
        'catatan' => ['keterangan', 'catatan', 'note', 'notes'],
        'sumber_data' => ['sumber data', 'sumber', 'sumber informasi'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Required columns
    |--------------------------------------------------------------------------
    |
    | A row missing any of these is reported as a failed row and skipped — the
    | rest of the file still imports. NIM is the natural key, so without it a
    | row cannot be de-duplicated or assigned, which makes it worthless.
    |
    */

    'required' => ['nim'],

    /*
    |--------------------------------------------------------------------------
    | Import behaviour
    |--------------------------------------------------------------------------
    */

    // Rows per bulk upsert. Higher is faster but holds more in memory and
    // makes one statement larger than MySQL's max_allowed_packet sooner.
    'chunk' => 500,

    // Hard ceiling, so a mis-selected 2 GB file cannot run the server out of
    // disk or the queue out of time.
    'max_rows' => 100000,

    // Queue the import (true) or run it inside the request (false). Queued is
    // right in production; the request would time out on a large file.
    'queued' => env('STUDENT_IMPORT_QUEUED', true),

    // Rows read for the pre-import preview.
    'preview_rows' => 20,

    /*
    |--------------------------------------------------------------------------
    | Blank markers
    |--------------------------------------------------------------------------
    |
    | The source file writes "/" where a value is missing — in some columns for
    | every single row. Treating it as data would fill the database with 7.000
    | slashes and make every filter useless, so these are read as empty.
    |
    | "0" and "Tidak dikenal" are in the list because this export uses them as
    | placeholders too — an email column reading "0" is not an email.
    |
    */

    'blank_values' => ['/', '-', '0', 'n/a', 'na', 'null', '#n/a', 'tidak dikenal', 'tidak diketahui'],

    'disk' => env('STUDENT_IMPORT_DISK', 'local'),
    'directory' => 'student-imports',

    /*
    |--------------------------------------------------------------------------
    | Existing rows
    |--------------------------------------------------------------------------
    |
    | What to do when an imported NIM is already in the database.
    |
    |   update  — refresh the student's data, keep their assignment (default)
    |   skip    — count it as a duplicate and change nothing
    |
    | Assignment is NEVER touched by an import either way: re-uploading the
    | list must not silently take students away from the operator holding them.
    |
    */

    'on_duplicate' => 'update',

];
