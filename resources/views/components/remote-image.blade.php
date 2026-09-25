@props([
    'src' => null,
    'alt' => '',
    'initial' => null,   // huruf untuk avatar
    'icon' => 'image',   // ikon untuk gambar non-avatar
    'label' => null,     // teks kecil di bawah ikon, mis. "Foto kedaluwarsa"
])

{{--
    An image from someone else's CDN.

    Instagram signs its image URLs and stamps an expiry into them, so a link
    that worked last month now returns nothing. A bare <img> renders the
    browser's broken-image icon for that — which is what "gambar rusak" is.

    This draws the fallback FIRST and lays the image over it, so a dead link
    simply reveals what was already underneath. Nothing shifts, nothing breaks,
    and the reader is told why rather than shown a torn-page glyph.
--}}
<span {{ $attributes->merge(['class' => 'relative block overflow-hidden']) }}>

    {{-- Fallback layer, always rendered. --}}
    <span class="absolute inset-0 flex flex-col items-center justify-center gap-1
                 bg-slate-100 text-slate-400 dark:bg-white/5 dark:text-slate-500">
        @if ($initial)
            <span class="text-[0.9em] font-semibold uppercase leading-none">{{ $initial }}</span>
        @else
            <x-icon :name="$icon" class="h-1/3 max-h-6 w-auto opacity-60"/>
            @if ($label)
                <span class="px-1 text-center text-[10px] leading-tight">{{ $label }}</span>
            @endif
        @endif
    </span>

    @if ($src)
        {{-- Removes itself when the link is dead, uncovering the fallback.
             referrerpolicy is required: several CDNs reject requests that carry
             our referrer. --}}
        <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" referrerpolicy="no-referrer"
             class="relative h-full w-full object-cover" onerror="this.remove()">
    @endif
</span>
