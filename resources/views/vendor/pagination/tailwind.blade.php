@php
    /**
     * Paginasi aplikasi.
     *
     * Menggantikan bawaan Laravel, yang memakai palet gray-* dengan cincin
     * fokus biru — dua-duanya asing di aplikasi ini (slate + ungu brand) dan
     * teksnya berbahasa Inggris di tengah antarmuka berbahasa Indonesia.
     *
     * Jumlah baris ditulis di kiri karena itu yang pertama dicari orang saat
     * memfilter: "ketemu berapa?", bukan "ini halaman berapa?".
     */
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navigasi halaman"
         class="flex flex-col items-center justify-between gap-3 sm:flex-row">

        {{-- Ringkasan --}}
        <p class="order-2 text-xs text-slate-400 sm:order-1">
            Menampilkan
            <span class="pg-count">{{ $paginator->firstItem() }}</span>&ndash;<span class="pg-count">{{ $paginator->lastItem() }}</span>
            dari <span class="pg-count">{{ number_format($paginator->total()) }}</span> data
        </p>

        <div class="order-1 flex items-center gap-1 sm:order-2">

            {{-- Sebelumnya --}}
            @if ($paginator->onFirstPage())
                <span class="pg-arrow pg-disabled" aria-disabled="true" aria-label="Halaman sebelumnya">
                    <x-icon name="chevron-left" class="h-4 w-4"/>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="pg-arrow" aria-label="Halaman sebelumnya">
                    <x-icon name="chevron-left" class="h-4 w-4"/>
                </a>
            @endif

            {{-- Nomor halaman. Disembunyikan di layar sempit: di ponsel dua
                 panah sudah cukup, dan deretan angka memaksa baris membungkus. --}}
            <div class="hidden items-center gap-1 sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="pg-gap" aria-hidden="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="pg-link pg-active" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="pg-link" aria-label="Halaman {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            {{-- Di ponsel: posisi halaman sebagai teks, bukan deretan angka. --}}
            <span class="pg-link pg-active sm:hidden">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            {{-- Berikutnya --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="pg-arrow" aria-label="Halaman berikutnya">
                    <x-icon name="chevron-right" class="h-4 w-4"/>
                </a>
            @else
                <span class="pg-arrow pg-disabled" aria-disabled="true" aria-label="Halaman berikutnya">
                    <x-icon name="chevron-right" class="h-4 w-4"/>
                </span>
            @endif
        </div>
    </nav>
@elseif ($paginator->total() > 0)
    {{-- Satu halaman saja: tetap sebutkan jumlahnya. Setelah memfilter, "12 data"
         adalah jawaban yang dicari — ruang kosong bukan jawaban. --}}
    <p class="text-xs text-slate-400">
        Menampilkan seluruh <span class="pg-count">{{ number_format($paginator->total()) }}</span> data
    </p>
@endif
