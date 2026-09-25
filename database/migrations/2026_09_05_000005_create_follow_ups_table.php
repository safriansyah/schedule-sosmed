<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every touch a member of staff makes, in order.
 *
 * Following someone up is rarely one action — it is a call, then a WhatsApp a
 * week later, then a brochure. This table is the append-only log of that, so
 * "who did what, when, and what came of it" survives staff changes.
 *
 * Polymorphic because the same log serves an interaction (this comment was
 * answered), a lead (this prospect was called) and a contact (general notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();

            // Written out rather than uuidMorphs() because the targets do not
            // share a key type: interactions and contacts are UUIDs, leads are
            // auto-increment. A string id holds either without a second table.
            $table->string('followupable_type');
            $table->string('followupable_id', 36);

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Snapshot of the role at the time — people get promoted, and a log
            // that silently rewrites history is worthless in an audit.
            $table->string('role_at_time', 32)->nullable();

            $table->string('channel_used', 24)->nullable();  // wa|dm|telepon|email|tatap_muka
            $table->string('action', 32);                    // dibalas|ditelepon|kirim_brosur|dijadwalkan|tidak_respon
            $table->text('response_text')->nullable();       // the "isi tanggapan"

            $table->string('outcome', 24)->nullable();       // positif|netral|negatif|belum_jelas
            $table->timestamp('next_action_at')->nullable()->index();

            $table->timestamps();

            $table->index(['followupable_type', 'followupable_id'], 'follow_ups_target_index');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
