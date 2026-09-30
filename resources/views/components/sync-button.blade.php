@props(['lastSyncedAt' => null])

{{--
    "Sinkron sekarang" — queues the work and reports on it.

    The old version POSTed the form and waited: the sync ran inside the request,
    one Instagram API call per post, so the page sat there for a minute and
    people clicked again. Now the click only enqueues, and this polls until the
    worker is done.

    It also has to survive the queue worker not running at all, which on a
    desktop that gets switched off is the normal failure. The status endpoint
    reports that case and the hint is shown here rather than leaving a spinner
    turning forever.
--}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}
     x-data="{
        state: 'idle',
        message: '',
        hint: null,
        lastSynced: @js($lastSyncedAt?->diffForHumans()),
        timer: null,

        get busy() { return this.state === 'queued' || this.state === 'running' },

        init() {
            // A run started from another tab, or before this page was opened,
            // should still be reflected here.
            this.check(false)
        },

        async start() {
            if (this.busy) return

            this.state = 'queued'
            this.message = 'Menunggu antrean…'
            this.hint = null

            try {
                // Relative, not route()'s absolute URL. This page is opened
                // over the LAN by IP while APP_URL commonly still says
                // localhost, and an absolute URL would send the browser to
                // localhost on the VIEWER's machine, where nothing is running.
                const response = await fetch(@js(route('sync.now', absolute: false)), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                })

                if (!response.ok) throw new Error('HTTP ' + response.status)
            } catch (e) {
                this.state = 'failed'
                this.message = 'Tidak bisa memulai sinkron.'
                return
            }

            this.poll()
        },

        poll() {
            clearInterval(this.timer)
            this.timer = setInterval(() => this.check(true), 2000)
        },

        async check(keepPolling) {
            let data

            try {
                const response = await fetch(@js(route('sync.status', absolute: false)), {
                    headers: { 'Accept': 'application/json' },
                })
                data = await response.json()
            } catch (e) {
                return
            }

            this.state = data.status
            this.message = data.message
            this.hint = data.hint
            if (data.last_synced_at) this.lastSynced = data.last_synced_at

            if (data.stalled) {
                // Nothing is coming. Stop turning and say why.
                this.state = 'failed'
                clearInterval(this.timer)
                return
            }

            if (this.busy) {
                if (keepPolling && !this.timer) this.poll()
                return
            }

            clearInterval(this.timer)
            this.timer = null

            // Reload so the page actually shows the numbers that were just
            // fetched — the whole reason for pressing the button.
            if (this.state === 'done') {
                setTimeout(() => window.location.reload(), 1200)
            }
        },
     }">

    <div class="hidden text-right sm:block">
        <p class="text-xs" :class="state === 'failed' ? 'text-rose-500' : 'text-slate-400'"
           x-text="message || (lastSynced ? 'Sinkron ' + lastSynced : '')"></p>

        <p x-show="hint" x-cloak class="max-w-xs text-xs text-amber-600 dark:text-amber-400" x-text="hint"></p>
    </div>

    <button type="button" class="btn-outline" @click="start()" :disabled="busy">
        <span :class="busy && 'animate-spin'" class="inline-flex">
            <x-icon name="refresh" class="h-4 w-4"/>
        </span>
        <span x-text="busy ? 'Menyinkron…' : 'Sinkron Sekarang'"></span>
    </button>
</div>
