@props(['message' => null])

@php
    // The official UT Pangkalpinang WhatsApp (config/services.php → ut.whatsapp).
    $number = \App\Support\PhoneNumber::normalize((string) config('services.ut.whatsapp'));
@endphp

@if ($number)
    <a href="{{ \App\Support\PhoneNumber::waLink($number, $message) }}" target="_blank" rel="noopener"
       {{ $attributes->merge(['class' => 'flex items-center justify-center gap-2 rounded-2xl border border-emerald-500/30 bg-emerald-500/[0.06] px-4 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-500/10 dark:text-emerald-300']) }}>
        <x-icon name="whatsapp" class="h-5 w-5"/>
        <span>Chat WhatsApp UT Pangkalpinang <span class="font-mono text-xs opacity-80">({{ \App\Support\PhoneNumber::pretty($number) }})</span></span>
    </a>
@endif
