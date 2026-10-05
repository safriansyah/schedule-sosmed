<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku Tamu / Antrian — walk-in visitors taking a queue number.
 *
 * The number is (queue_date, queue_number): it restarts at 1 every day, and
 * the unique index is what makes two visitors submitting in the same instant
 * end up with different numbers rather than the same one.
 *
 * `ticket_id` is unique as well, so one entry can become at most one ticket —
 * the database refuses a second, whatever happens to the button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_book_entries', function (Blueprint $table) {
            $table->id();

            $table->date('queue_date');
            $table->unsignedSmallInteger('queue_number');

            $table->string('whatsapp', 32);
            $table->string('phone', 32);
            $table->string('name');
            $table->string('nim', 32)->nullable()->index();
            $table->string('gender', 16);
            $table->string('service', 48)->index();
            $table->text('description')->nullable();

            // The paraf, drawn on the form, kept on the private disk: it is a
            // person's signature, not something to hand out by URL.
            $table->string('signature_path')->nullable();

            $table->string('status', 16)->default('waiting');
            $table->foreignId('ticket_id')->nullable()->unique()->constrained('tickets')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // For tracing abuse of a public form, nothing else.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->unique(['queue_date', 'queue_number']);
            $table->index(['queue_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_book_entries');
    }
};
