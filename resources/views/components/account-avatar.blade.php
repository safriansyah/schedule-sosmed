@props(['account', 'size' => 'h-12 w-12', 'ring' => 'ring-2 ring-brand-500/40'])

{{--
    Profile picture of a connected account.

    The platform-coloured badge is drawn underneath and always present, so when
    the Instagram link expires — which it does within days — the image removes
    itself and a branded circle shows through instead of a broken-image icon.

    Previously this was three near-identical blocks across the dashboard,
    accounts list and monitoring header; they drifted apart, and none of them
    handled a dead link.
--}}
<span {{ $attributes->merge(['class' => "relative shrink-0 overflow-hidden rounded-full {$size} {$ring}"]) }}>

    <span class="absolute inset-0 grid place-items-center text-white"
          style="background: {{ $account->platform->color() }}">
        <x-icon :name="$account->platform->icon()" class="h-2/5 w-auto"/>
    </span>

    @if ($account->avatar())
        <img src="{{ $account->avatar() }}" alt="{{ $account->name }}" loading="lazy"
             referrerpolicy="no-referrer"
             class="relative h-full w-full object-cover" onerror="this.remove()">
    @endif
</span>
