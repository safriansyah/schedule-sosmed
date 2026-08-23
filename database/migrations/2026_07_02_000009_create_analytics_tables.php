<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two grains of analytics:
     *  - account_metrics: one row per account per day (followers, totals)
     *  - content_metrics: one row per published content per day (post insights)
     *
     * Daily snapshots are what make day/week/month/year comparisons possible.
     */
    public function up(): void
    {
        Schema::create('account_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();

            $table->date('captured_on')->index();

            $table->unsignedBigInteger('followers')->default(0);
            $table->unsignedBigInteger('follows')->default(0);
            $table->unsignedBigInteger('media_count')->default(0);

            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);

            $table->decimal('engagement_rate', 8, 4)->default(0);

            $table->timestamps();

            $table->unique(['social_account_id', 'captured_on']);
        });

        Schema::create('content_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();

            $table->date('captured_on')->index();

            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);

            $table->decimal('engagement_rate', 8, 4)->default(0);

            $table->timestamps();

            $table->unique(['content_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_metrics');
        Schema::dropIfExists('account_metrics');
    }
};
