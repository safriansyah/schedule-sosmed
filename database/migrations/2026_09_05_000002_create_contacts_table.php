<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PERSON — not an account. This is the "UID" the whole CRM hangs off.
 *
 * One human may comment from two Instagram accounts, message from TikTok and
 * be reachable on WhatsApp; all of those live in `contact_identities` and
 * resolve back to one row here. Everything downstream (agent status, lead
 * funnel, follow-up history) keys off this id.
 *
 * UUID rather than an auto-increment key on purpose: these rows carry PII
 * (real name, phone, address) and appear in URLs, so sequential ids would let
 * anyone with one link enumerate the entire contact database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Human-readable handle used in conversation ("cek UT-000142").
            $table->string('code', 24)->unique();

            $table->string('display_name')->nullable();   // whatever the platform shows
            $table->string('full_name')->nullable();      // real name — filled by an operator
            $table->string('avatar_url', 1024)->nullable();

            // Stored in E.164 without the plus (628123…), so the same number
            // typed as 08123…, +628123… or 628123… matches one contact.
            $table->string('phone_e164', 20)->nullable()->unique();
            $table->string('email')->nullable();

            // non_agent | candidate | agent | blacklist
            $table->string('status', 24)->default('non_agent')->index();
            $table->string('agent_code', 32)->nullable()->unique();
            $table->timestamp('agent_since')->nullable();
            $table->foreignId('recruited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('address_detail', 512)->nullable();

            // 0–100. Rolling estimate of "could become a student", fed by the
            // classifier and adjusted by hand.
            $table->unsignedTinyInteger('potential_score')->default(0)->index();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            // Set when this row turned out to be a duplicate of another contact.
            // The row is kept (old interactions still point at it) but every
            // read follows the pointer.
            $table->uuid('merged_into_id')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
