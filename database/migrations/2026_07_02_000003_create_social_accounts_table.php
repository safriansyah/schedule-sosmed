<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('platform')->index();             // SocialPlatform
            $table->string('name');                          // display name
            $table->string('username')->nullable();
            $table->string('external_id')->nullable()->index(); // platform-side account id

            $table->text('access_token')->nullable();        // encrypted cast
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->string('avatar_url', 1024)->nullable();
            $table->unsignedBigInteger('followers_count')->default(0);
            $table->unsignedBigInteger('media_count')->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['platform', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
