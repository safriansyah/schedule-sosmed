<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained('contents')->cascadeOnDelete();

            $table->string('type')->default('image')->index();  // MediaType
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable();     // seconds, video only

            $table->unsignedSmallInteger('position')->default(0); // carousel order

            $table->timestamps();
            $table->softDeletes();

            $table->index(['content_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
