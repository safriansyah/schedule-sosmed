<x-layouts.app title="Detail Postingan">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                {{ $media->shortCaption(70) }}
            </h1>
            <p class="mt-0.5 text-sm text-slate-400">
                {{ $media->account->handle() }} ·
                {{ $media->posted_at?->translatedFormat('l, d F Y — H:i') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('monitoring.index', ['account' => $media->social_account_id]) }}" class="btn-outline">
                <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
            </a>

            @if ($media->permalink)
                <a href="{{ $media->permalink }}" target="_blank" rel="noopener" class="btn-outline">
                    <x-icon name="link" class="h-4 w-4"/> Buka di Instagram
                </a>
            @endif

            @if ($media->content_id)
                <a href="{{ route('contents.show', $media->content_id) }}" class="btn-primary">
                    <x-icon name="file-text" class="h-4 w-4"/> Lihat Konten
                </a>
            @endif
        </div>
    </x-slot:header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Media --}}
        <div class="space-y-6">
            <div class="card overflow-hidden">
                @if ($media->thumbnail_url)
                    <img src="{{ $media->thumbnail_url }}" alt="" class="aspect-square w-full object-cover">
                @else
                    <div class="grid aspect-square place-items-center bg-slate-100 text-slate-300 dark:bg-white/5">
                        <x-icon name="image" class="h-10 w-10"/>
                    </div>
                @endif

                <div class="p-4">
                    <p class="flex flex-wrap items-center gap-1.5">
                        <span class="badge-slate">{{ $media->isReel() ? 'Reels' : ucfirst(strtolower($media->product_type ?? 'Feed')) }}</span>
                        <span class="badge-slate">{{ ucfirst(strtolower($media->media_type ?? '-')) }}</span>
                        @if ($media->content_id)
                            <span class="badge-violet">Terbit dari aplikasi</span>
                        @endif
                    </p>

                    @if ($media->caption)
                        <p class="mt-3 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $media->caption }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Metrics --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Current totals --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach ([
                    ['Like', 'heart', 'rose', $latest?->likes],
                    ['Comment', 'message', 'cyan', $latest?->comments],
                    ['Views', 'eye', 'amber', $latest?->views],
                    ['Reach', 'target', 'emerald', $latest?->reach],
                    ['Save', 'bookmark', 'brand', $latest?->saves],
                    ['Share', 'share', 'pink', $latest?->shares],
                ] as [$label, $icon, $tone, $value])
                    <x-stat-card :label="$label" :value="number_format($value ?? 0)" :icon="$icon" :tone="$tone"/>
                @endforeach
            </div>

            {{-- Growth windows --}}
            <div class="card p-5">
                <p class="mb-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Kenaikan</p>
                <p class="mb-4 text-xs text-slate-400">Selisih antar snapshot yang tersimpan.</p>

                @if (filled($growth))
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[520px]">
                            <thead class="border-b border-slate-100 dark:border-white/5">
                                <tr>
                                    <th class="th">Rentang</th>
                                    <th class="th text-right">Like</th>
                                    <th class="th text-right">Comment</th>
                                    <th class="th text-right">Views</th>
                                    <th class="th text-right">Reach</th>
                                    <th class="th text-right">Save</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                                @foreach ($growth as $label => $values)
                                    <tr class="row-hover">
                                        <td class="td font-medium">{{ $label }}</td>
                                        @foreach (['likes', 'comments', 'views', 'reach', 'saves'] as $key)
                                            <td class="td text-right">
                                                @if (($values[$key] ?? 0) > 0)
                                                    <span class="badge-green">+{{ number_format($values[$key]) }}</span>
                                                @else
                                                    <span class="text-slate-400">0</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-empty-state icon="clock" title="Belum ada pembanding"
                                   description="Kenaikan muncul setelah ada minimal dua snapshot. Sinkronisasi berjalan otomatis setiap hari."
                                   class="!py-8"/>
                @endif
            </div>

            {{-- Comments --}}
            <div class="card p-5">
                <div class="mb-4 flex items-center justify-between">
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Komentar
                        <span class="text-slate-400">({{ number_format($latest?->comments ?? 0) }})</span>
                    </p>
                </div>

                @forelse ($comments as $comment)
                    <x-comment-item :comment="$comment"/>
                @empty
                    <x-empty-state
                        icon="message"
                        :title="($latest?->comments ?? 0) > 0 ? number_format($latest->comments).' komentar tercatat' : 'Belum ada komentar'"
                        :description="($latest?->comments ?? 0) > 0
                            ? 'Jumlah komentar termonitor. Isi & nama pengomentar tersinkron otomatis setiap 5 jam — muncul di sini setelah sinkronisasi berikutnya.'
                            : 'Komentar akan tampil di sini setelah ada.'"
                        class="!py-8"/>
                @endforelse

                @if ($comments->hasPages())
                    <div class="mt-4">{{ $comments->links() }}</div>
                @endif
            </div>

            {{-- History chart --}}
            @if ($snapshots->count() > 1)
                <x-chart
                    title="Riwayat Metrik"
                    subtitle="Total kumulatif pada setiap snapshot"
                    :height="300"
                    :options="[
                        'series' => [
                            ['name' => 'Like', 'data' => $snapshots->pluck('likes')->all()],
                            ['name' => 'Views', 'data' => $snapshots->pluck('views')->all()],
                            ['name' => 'Reach', 'data' => $snapshots->pluck('reach')->all()],
                        ],
                        'chart' => ['type' => 'line'],
                        'xaxis' => ['categories' => $snapshots->map(fn ($s) => $s->captured_at?->translatedFormat('d M H:i'))->all()],
                    ]"/>
            @endif
        </div>
    </div>
</x-layouts.app>
