<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->unique();
            $table->foreignId('role_id')->nullable()->after('uuid')->constrained()->nullOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('avatar_path');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->softDeletes();
        });

        // Backfill UUIDs for any existing accounts.
        foreach (DB::table('users')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['uuid', 'phone', 'avatar_path', 'is_active', 'last_login_at', 'deleted_at']);
        });
    }
};
