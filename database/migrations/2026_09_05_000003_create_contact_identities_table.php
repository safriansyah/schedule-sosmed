<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accounts a contact is known by, one row per channel handle.
 *
 * Deliberately NOT fixed columns on `contacts` (ig_username, tiktok_username…):
 * people routinely have two Instagram accounts, and a fixed column silently
 * loses the second one.
 *
 * `external_id` is the platform's numeric id where we have it — usernames get
 * changed, numeric ids do not — with the handle kept for display and for
 * matching when no id is available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();

            $table->string('channel', 32)->index();      // instagram|tiktok|youtube|whatsapp|facebook
            $table->string('handle')->nullable();        // @username / phone / channel handle
            $table->string('external_id')->nullable();   // stable platform id

            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // Two lookup paths, both must stay unambiguous. Nullable columns are
            // exempt from UNIQUE in MySQL, so a row with only a handle and a row
            // with only an id can coexist without colliding.
            $table->unique(['channel', 'external_id']);
            $table->unique(['channel', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_identities');
    }
};
