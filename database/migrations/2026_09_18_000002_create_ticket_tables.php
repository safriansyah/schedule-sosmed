<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticketing: the place a case that needs following up is worked.
 *
 * Three tables.
 *
 * `ticket_categories` is self-referential rather than a category table plus a
 * sub-category table. The brief lists both, but they hold identical columns and
 * a second level is the only difference — one table with `parent_id` gives the
 * same two levels (the UI enforces the depth), lets a sub-category be renamed
 * or moved without a data migration, and matches how `regions` already models
 * its hierarchy here.
 *
 * `tickets` snapshots its social-media origin instead of only pointing at the
 * interaction. Interactions are re-synced from an undocumented third-party
 * viewer and can change or vanish; a ticket must still read correctly in a
 * year's time, so the username, comment text and post URL are copied in. The
 * FK is kept as well, for when the live row is still there.
 *
 * Follow-ups are NOT here: they go in the existing polymorphic `follow_ups`
 * table, which already gives one ticket many touches with author, channel and
 * outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            // Null = a top-level category; set = a sub-category of that row.
            $table->foreignId('parent_id')->nullable()->constrained('ticket_categories')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 128);
            $table->string('description', 512)->nullable();
            $table->string('color', 24)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Unique within a parent, not globally: "Pembayaran" may exist under
            // two different parents without clashing.
            $table->unique(['parent_id', 'slug']);
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // TKT-00001. Generated in one transaction-safe step (see Ticket).
            $table->string('number', 24)->unique();

            $table->string('source', 32)->index();
            $table->string('subject');
            $table->text('description')->nullable();

            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->foreignId('sub_category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();

            $table->string('status', 24)->default('open')->index();
            $table->string('priority', 16)->default('normal')->index();

            /* ---------- who this is about ---------- */

            // Both optional: a ticket may be about an imported student, about a
            // contact from the social CRM, about both, or about a walk-in whose
            // details are only in the fields below.
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Snapshot of the requester. Filled from the student/contact when
            // there is one, typed by hand when there is not — so a ticket is
            // readable without following any relation.
            $table->string('requester_name')->nullable();
            $table->string('requester_nim', 32)->nullable()->index();
            $table->string('requester_nac', 64)->nullable();
            $table->string('requester_phone', 32)->nullable();
            $table->string('requester_email')->nullable();

            /* ---------- where it came from ---------- */

            $table->foreignUuid('interaction_id')->nullable()->constrained('interactions')->nullOnDelete();
            $table->string('source_channel', 32)->nullable();
            $table->string('source_external_id')->nullable();   // the comment id
            $table->string('source_username')->nullable();
            $table->text('source_text')->nullable();
            $table->string('source_post_id')->nullable();
            $table->string('source_post_url', 1024)->nullable();
            $table->timestamp('source_created_at')->nullable();

            /* ---------- handling ---------- */

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_follow_up_at')->nullable();
            $table->unsignedInteger('follow_up_count')->default(0);
            $table->timestamp('due_at')->nullable();

            $table->text('resolution_note')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->json('extra')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // "My open tickets, newest first" and "this status, newest first"
            // are the two list screens; index for both.
            $table->index(['assigned_to', 'status', 'created_at']);
            $table->index(['status', 'priority', 'created_at']);
            // One ticket per source comment — enforced in code, helped here.
            $table->index(['source', 'source_external_id']);
        });

        Schema::create('ticket_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();

            // Null `to_user_id` means the ticket was un-assigned back to the pool.
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('note', 512)->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_assignments');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_categories');
    }
};
