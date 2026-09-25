<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns `students` with the real spreadsheet, now that it has been supplied.
 *
 * The placeholder columns were a guess made before the file existed. The real
 * "DATA INDUK PROGRES REGISTRASI" carries 44 columns, and these are the ones
 * worth promoting out of the `extra` JSON because screens filter, search or
 * display on them. Everything else still lands in `extra` untouched.
 *
 * `kategori_masalah` also changes vocabulary: the invented five conditions are
 * replaced by the eight the institution actually uses, so the existing rows are
 * translated here rather than left to fail their enum cast.
 */
return new class extends Migration
{
    /** Old placeholder value => closest real one. */
    private const CONDITION_MAP = [
        'registrasi_belum_bayar' => 'admisi_tidak_bayar',
        'billing_nac_belum_bayar' => 'ongoing_billing_pending',
        'belum_registrasi_matkul' => 'maba_belum_reg_mk',
        'belum_registrasi' => 'ongoing_tidak_registrasi',
    ];

    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Segmentation: "Maba Proses Registrasi" vs "Ongoing Proses
            // Registrasi". Two values, but they split the whole list in half
            // and every report wants them apart.
            $table->string('segmen', 64)->nullable()->after('kategori_masalah')->index();

            // Kelompok belajar / SALUT. A real working dimension — staff are
            // organised around it.
            $table->string('pokjar', 160)->nullable()->after('kecamatan')->index();
            $table->string('wilayah_ujian', 160)->nullable()->after('pokjar');

            // Registration state as the source system records it: DN / DA / DS.
            $table->string('status_dp', 24)->nullable()->after('status_registrasi_matkul')->index();
            $table->string('sipas', 160)->nullable()->after('status_dp');

            // Masa registrasi pertama & terakhir (e.g. 20221).
            $table->string('mri', 16)->nullable()->after('semester_terakhir');
            $table->string('mra', 16)->nullable()->after('mri');

            $table->string('alamat', 512)->nullable()->after('kelurahan');
            $table->string('telp', 32)->nullable()->after('no_hp_raw');
            $table->string('hp2', 32)->nullable()->after('telp');
            $table->string('email_alternatif')->nullable()->after('email');

            // The officer named in the spreadsheet. Deliberately TEXT and not a
            // users foreign key: these are staff names, and importing a name is
            // not grounds for creating a login. The importer links to a user
            // only when one with that exact name already exists.
            $table->string('petugas_nama')->nullable()->after('assigned_by')->index();
        });

        foreach (self::CONDITION_MAP as $old => $new) {
            DB::table('students')->where('kategori_masalah', $old)->update(['kategori_masalah' => $new]);
        }
    }

    public function down(): void
    {
        foreach (array_flip(self::CONDITION_MAP) as $new => $old) {
            DB::table('students')->where('kategori_masalah', $new)->update(['kategori_masalah' => $old]);
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'segmen', 'pokjar', 'wilayah_ujian', 'status_dp', 'sipas',
                'mri', 'mra', 'alamat', 'telp', 'hp2', 'email_alternatif', 'petugas_nama',
            ]);
        });
    }
};
