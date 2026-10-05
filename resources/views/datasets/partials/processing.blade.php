<div class="card p-10"
     x-data="{ status: @js($dataset->status), progress: {{ $dataset->progress }}, error: @js($dataset->error_message) }"
     x-init="
        if (status === 'pending' || status === 'processing') {
            let id = setInterval(async () => {
                let r = await (await fetch('{{ route('datasets.status', $dataset) }}', {headers:{Accept:'application/json'}})).json();
                status = r.status; progress = r.progress; error = r.error_message;
                if (r.ready) { clearInterval(id); window.toast('Impor selesai.'); setTimeout(() => location.reload(), 600); }
                if (r.status === 'failed') clearInterval(id);
            }, 2500);
        }
     ">

    <template x-if="status !== 'failed'">
        <div class="mx-auto max-w-md text-center">
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10">
                <x-icon name="refresh" class="w-8 h-8 animate-spin"/>
            </div>
            <h2 class="mt-6 text-xl font-bold text-slate-800 dark:text-white">
                Memproses dataset Anda
            </h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Membaca &amp; menyimpan baris secara bertahap. Halaman ini diperbarui otomatis — Anda boleh meninggalkannya dan kembali nanti.
            </p>

            <div class="mt-8">
                <div class="mb-2 flex justify-between text-sm font-medium text-slate-500 dark:text-slate-400">
                    <span x-text="status === 'pending' ? 'Dalam antrean' : 'Mengimpor'"></span>
                    <span x-text="progress + '%'"></span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                    <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-accent-500 transition-all duration-700"
                         :style="`width:${Math.max(progress, 4)}%`"></div>
                </div>
            </div>
        </div>
    </template>

    <template x-if="status === 'failed'">
        <div class="mx-auto max-w-md text-center">
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10">
                <x-icon name="alert" class="w-8 h-8"/>
            </div>
            <h2 class="mt-6 text-xl font-bold text-slate-800 dark:text-white">Impor gagal</h2>
            <p class="mt-2 break-words rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"
               x-text="error || 'Terjadi kesalahan tidak dikenal saat membaca JSON.'"></p>
            @can(\App\Enums\Permission::ManageDatasets->value)
            <button type="button" @click="$dispatch('open-modal','replace')" class="btn-primary mt-6">
                <x-icon name="upload" class="w-4 h-4"/> Unggah ulang file
            </button>
            @endcan
        </div>
    </template>
</div>
