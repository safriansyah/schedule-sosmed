@props([
    'options' => [25, 50, 100, 200, 500],
    'current' => 25,
    'total' => null,
])

{{--
    "Tampilkan N per halaman".

    A GET form rather than a link per option, so every other filter currently
    in the query string is carried along as hidden inputs instead of being
    dropped the moment someone changes the page size.

    `page` is deliberately NOT carried: showing 500 rows starting from what was
    page 7 of 50 would land the user somewhere they did not ask to be.
--}}
<form method="GET" {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    @foreach (request()->except(['per_page', 'page']) as $key => $value)
        @if (is_array($value))
            @foreach ($value as $item)
                <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
            @endforeach
        @else
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach

    <label for="per_page" class="shrink-0 text-xs text-slate-400">Tampilkan</label>

    <select id="per_page" name="per_page" class="input !w-auto !py-1.5 !text-xs" onchange="this.form.submit()">
        @foreach ($options as $option)
            <option value="{{ $option }}" @selected((int) $current === (int) $option)>{{ $option }}</option>
        @endforeach
    </select>

    @if ($total !== null)
        <span class="hidden shrink-0 text-xs text-slate-400 sm:inline">dari {{ number_format($total) }}</span>
    @endif

    {{-- Still works with JavaScript off. --}}
    <noscript>
        <button class="btn-outline btn-sm">Terapkan</button>
    </noscript>
</form>
