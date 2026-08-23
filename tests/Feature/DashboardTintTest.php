<?php
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
uses(DatabaseTransactions::class);
it('tints the dashboard workflow cards', function () {
    $html = $this->actingAs(User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->get(route('dashboard'))->assertOk()->getContent();
    // whole-card wash classes present
    expect($html)->toContain('!bg-violet-500/[0.06]');   // Total Konten
    expect($html)->toContain('!bg-sky-500/[0.06]');      // Draft
    expect($html)->toContain('!bg-amber-500/[0.06]');    // Menunggu Approval
    expect($html)->toContain('!bg-pink-500/[0.06]');     // Revisi
});
