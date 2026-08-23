<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Comments observed on a monitored post. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_media_id')->constrained('account_media')->cascadeOnDelete();

            $table->string('external_id')->index();
            $table->string('username')->nullable();
            $table->text('text')->nullable();
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamp('commented_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['account_media_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_comments');
    }
};
