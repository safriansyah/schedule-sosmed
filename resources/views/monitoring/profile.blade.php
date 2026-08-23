<x-layouts.app :title="'@'.$username">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                Profil &commat;{{ $username }}
            </h1>
            <p class="mt-0.5 text-sm text-slate-400">Data publik akun pengomentar.</p>
        </div>

        <a href="{{ url()->previous() }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    {{-- Profile card --}}
    <div class="card p-6">
        @php $igUrl = $profile['url'] ?? 'https://www.instagram.com/'.$username.'/'; @endphp

        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            <span class="avatar relative mx-auto h-20 w-20 shrink-0 overflow-hidden text-2xl sm:mx-0">
                {{ strtoupper(mb_substr($username, 0, 1)) }}
                @if ($profile && $profile['avatar_url'])
                    <img src="{{ $profile['avatar_url'] }}" alt="" referrerpolicy="no-referrer"
                         class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
                @endif
            </span>

            <div class="min-w-0 flex-1 text-center sm:text-left">
                <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                    <p class="text-lg font-bold text-slate-800 dark:text-white">
                        {{ $profile['full_name'] ?? '@'.$username }}
                    </p>
                    @if ($profile && $profile['is_verified'])
                        <x-icon name="badge-check" class="h-4 w-4 text-sky-500"/>
                    @endif
                    @if ($profile && $profile['is_private'])
                        <span class="badge-slate">Privat</span>
                    @endif
                </div>

                <p class="text-sm text-slate-400">&commat;{{ $profile['username'] ?? $username }}</p>

                @if ($profile && $profile['bio'])
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $profile['bio'] }}</p>
                @endif
            </div>

            <a href="{{ $igUrl }}" target="_blank" rel="noopener" class="btn-primary shrink-0">
                <x-icon name="link" class="h-4 w-4"/> Buka di Instagram
            </a>
        </div>

        @if ($profile)
            <div class="mt-6 grid grid-cols-3 gap-4 border-t border-slate-100 pt-5 text-center dark:border-white/5">
                <div>
                    <p class="text-xl font-extrabold text-slate-800 dark:text-white">{{ number_format($profile['media_count']) }}</p>
                    <p class="text-[11px] text-slate-400">Postingan</p>
                </div>
                <div>
                    <p class="text-xl font-extrabold text-slate-800 dark:text-white">{{ number_format($profile['followers']) }}</p>
                    <p class="text-[11px] text-slate-400">Followers</p>
                </div>
                <div>
                    <p class="text-xl font-extrabold text-slate-800 dark:text-white">{{ number_format($profile['following']) }}</p>
                    <p class="text-[11px] text-slate-400">Following</p>
                </div>
            </div>
        @else
            <div class="mt-6 flex items-start gap-3 border-t border-slate-100 pt-5 text-sm text-slate-500 dark:border-white/5 dark:text-slate-400">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-500"/>
                <p>Detail profil tidak dapat diambil saat ini (viewer tak merespons atau akun tidak ditemukan). Kamu masih bisa membukanya langsung di Instagram.</p>
            </div>
        @endif
    </div>

    {{-- Their comments on our posts --}}
    <div class="mt-6">
        <h2 class="mb-3 text-base font-bold text-slate-800 dark:text-white">
            Komentar &commat;{{ $username }} di postingan kita
            <span class="text-sm font-normal text-slate-400">({{ $comments->count() }})</span>
        </h2>

        <div class="card p-2">
            @forelse ($comments as $comment)
                <div class="px-2">
                    <x-comment-item :comment="$comment" :show-post="true"/>
                </div>
            @empty
                <x-empty-state icon="message" title="Belum ada komentar tercatat"
                               description="Akun ini belum tercatat berkomentar di postingan yang dipantau."
                               class="!py-8"/>
            @endforelse
        </div>
    </div>
</x-layouts.app>
