<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indonesian administrative regions, four levels deep:
 *
 *   provinsi → kabupaten/kota → kecamatan → desa/kelurahan
 *
 * Self-referential rather than four tables, so "everything under Sleman" is a
 * single recursive walk and adding a level later costs nothing. A contact
 * points at the deepest level it knows (usually desa); the levels above are
 * reachable through `parent_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('regions')->cascadeOnDelete();

            // Kemendagri code, e.g. "34.04.05.2003". Unique so an import can be
            // replayed with updateOrCreate instead of duplicating the country.
            $table->string('code', 32)->unique();
            $table->string('level', 16)->index();   // provinsi|kabupaten|kecamatan|desa
            $table->string('name');

            // Denormalised "Desa X, Kec. Y, Kab. Z, Prov. W" so list screens can
            // show a full address without four joins per row.
            $table->string('full_path', 512)->nullable();

            $table->timestamps();

            $table->index(['parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
