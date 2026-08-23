@props(['status' => 'draft'])

@php
    // Workflow statuses → label, tone and icon. Keys match the ContentStatus enum.
    $map = [
        'draft'                => ['Draft', 'badge-slate', 'file-text'],
        'waiting_approval'     => ['Menunggu Approval', 'badge-amber', 'clock'],
        'revision'             => ['Revisi', 'badge-pink', 'rotate'],
        'approved'             => ['Disetujui', 'badge-blue', 'check-circle'],
        'waiting_verification' => ['Menunggu Verifikasi', 'badge-cyan', 'clock'],
        'verified'             => ['Terverifikasi', 'badge-violet', 'badge-check'],
        'scheduled'            => ['Terjadwal', 'badge-blue', 'calendar'],
        'published'            => ['Terbit', 'badge-green', 'send'],
        'cancelled'            => ['Dibatalkan', 'badge-red', 'x'],
        'failed'               => ['Gagal', 'badge-red', 'alert'],

        // Dataset import lifecycle (UT Analytic Sosmed)
        'pending'              => ['Menunggu', 'badge-slate', 'clock'],
        'processing'           => ['Diproses', 'badge-amber', 'refresh'],
        'completed'            => ['Selesai', 'badge-green', 'check-circle'],
    ];

    $key = $status instanceof \BackedEnum ? $status->value : (string) $status;
    [$label, $tone, $icon] = $map[$key] ?? [ucfirst(str_replace('_', ' ', $key)), 'badge-slate', 'hash'];
@endphp

<span {{ $attributes->merge(['class' => $tone]) }}>
    <x-icon :name="$icon" class="h-3 w-3"/>
    {{ $label }}
</span>
