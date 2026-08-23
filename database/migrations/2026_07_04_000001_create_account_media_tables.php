<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observed posts on a connected account — including posts this app did not
 * publish itself — plus a daily snapshot of each post's insights.
 *
 * Insights from Instagram are *lifetime* totals, so storing one row per day
 * is what lets us derive "how much did it grow today / this week / this month".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();

            // Links back to our own content when we published it.
            $table->foreignUuid('content_id')->nullable()->constrained('contents')->nullOnDelete();

            $table->string('external_id')->index();          // platform media id
            $table->text('caption')->nullable();
            $table->string('media_type', 32)->nullable();     // IMAGE | VIDEO | CAROUSEL_ALBUM
            $table->string('product_type', 32)->nullable();   // FEED | REELS | STORY
            $table->string('permalink', 1024)->nullable();
            $table->string('thumbnail_url', 1024)->nullable();
            $table->timestamp('posted_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['social_account_id', 'external_id']);
        });

        Schema::create('media_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_media_id')->constrained('account_media')->cascadeOnDelete();

            $table->date('captured_on')->index();

            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('interactions')->default(0);

            $table->timestamps();

            $table->unique(['account_media_id', 'captured_on']);
        });

        // Account-level reach is available per day from the insights endpoint.
        Schema::table('account_metrics', function (Blueprint $table) {
            $table->unsignedBigInteger('interactions')->default(0)->after('saves');
        });
    }

    public function down(): void
    {
        Schema::table('account_metrics', fn (Blueprint $table) => $table->dropColumn('interactions'));
        Schema::dropIfExists('media_metrics');
        Schema::dropIfExists('account_media');
    }
};
