import './bootstrap';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import ApexCharts from 'apexcharts';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import { chartTheme, makeChart, platformColors } from './charts';
import { mediaPicker, MEDIA_SPEC } from './media-picker';

window.Alpine = Alpine;
window.ApexCharts = ApexCharts;
window.makeChart = makeChart;
window.chartTheme = chartTheme;
window.platformColors = platformColors;

Alpine.plugin(collapse);
Alpine.data('mediaPicker', mediaPicker);
window.MEDIA_SPEC = MEDIA_SPEC;

/* ---------------------------------------------------------------------------
 | Theme store — persisted dark/light mode (no flash: see layout head script)
 * ------------------------------------------------------------------------- */
Alpine.store('theme', {
    dark: document.documentElement.classList.contains('dark'),

    init() {
        this.dark = localStorage.getItem('theme')
            ? localStorage.getItem('theme') === 'dark'
            : window.matchMedia('(prefers-color-scheme: dark)').matches;
        this.apply();
    },

    toggle() {
        this.dark = !this.dark;
        this.apply();
    },

    apply() {
        document.documentElement.classList.toggle('dark', this.dark);
        localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: this.dark } }));
    },
});

/* ---------------------------------------------------------------------------
 | Toast store — lightweight notifications
 * ------------------------------------------------------------------------- */
Alpine.store('toasts', {
    items: [],

    push(message, type = 'success', timeout = 4500) {
        const id = Date.now() + Math.random();
        this.items.push({ id, message, type });
        if (timeout) setTimeout(() => this.remove(id), timeout);
    },

    remove(id) {
        this.items = this.items.filter((t) => t.id !== id);
    },
});

window.toast = (message, type) => Alpine.store('toasts').push(message, type);

/* ---------------------------------------------------------------------------
 | Sidebar UI store — mobile drawer + desktop compact mode
 * ------------------------------------------------------------------------- */
Alpine.store('ui', {
    sidebarOpen: false,
    collapsed: localStorage.getItem('sidebar-collapsed') === '1',

    toggleCollapsed() {
        this.collapsed = !this.collapsed;
        localStorage.setItem('sidebar-collapsed', this.collapsed ? '1' : '0');
    },
});

/* ---------------------------------------------------------------------------
 | <div x-data="apexChart({...})"> — declarative, theme-aware charts
 * ------------------------------------------------------------------------- */
Alpine.data('apexChart', (options) => ({
    chart: null,

    mount() {
        this.chart = window.makeChart(this.$refs.canvas, options);
    },

    replace(series, opts = {}) {
        if (this.chart) this.chart.updateOptions({ ...opts, series }, false, true);
    },

    destroy() {
        if (this.chart) this.chart.destroy();
    },
}));

/* ---------------------------------------------------------------------------
 | <div x-data="contentCalendar({...})"> — FullCalendar wrapper
 * ------------------------------------------------------------------------- */
Alpine.data('contentCalendar', ({ eventsUrl, moveUrl, noteUrl, canNote = false, editable = false }) => ({
    calendar: null,
    filters: { status: '', creator: '' },

    // Note editor state
    noteOpen: false,
    saving: false,
    note: { id: null, title: '', type: 'note', note: '', starts_at: '', is_all_day: true },

    mount() {
        this.calendar = new Calendar(this.$refs.calendar, {
            plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
            initialView: window.matchMedia('(max-width: 640px)').matches ? 'listWeek' : 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            },
            buttonText: {
                today: 'Hari ini', month: 'Bulan', week: 'Minggu', day: 'Hari', list: 'Agenda',
            },
            locale: 'id',
            firstDay: 1,
            height: 'auto',
            nowIndicator: true,
            editable,
            selectable: canNote,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },

            // Clicking / selecting an empty day opens the note editor.
            dateClick: (info) => canNote && this.openNote(info.dateStr),

            // Notes open the editor; content cards follow their link.
            eventClick: (info) => {
                const props = info.event.extendedProps;
                if (props.kind !== 'note') return;

                info.jsEvent.preventDefault();
                if (props.editable) this.editNote(info.event);
            },

            events: (info, success, failure) => {
                const params = new URLSearchParams({
                    start: info.startStr,
                    end: info.endStr,
                    ...(this.filters.status && { status: this.filters.status }),
                    ...(this.filters.creator && { creator: this.filters.creator }),
                });

                fetch(`${eventsUrl}?${params}`, { headers: { Accept: 'application/json' } })
                    .then((r) => r.json())
                    .then(success)
                    .catch(failure);
            },

            // Dragging a card reschedules the content.
            eventDrop: async (info) => {
                try {
                    const response = await fetch(moveUrl.replace('__ID__', info.event.id), {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({ scheduled_at: info.event.start.toISOString() }),
                    });

                    if (!response.ok) throw new Error();
                    window.toast('Jadwal diperbarui.', 'success');
                } catch {
                    info.revert();
                    window.toast('Gagal memindahkan jadwal.', 'error');
                }
            },
        });

        this.calendar.render();
    },

    refresh() {
        this.calendar?.refetchEvents();
    },

    /* --- Note editor ---------------------------------------------------- */

    openNote(dateStr) {
        this.note = { id: null, title: '', type: 'note', note: '', starts_at: dateStr.slice(0, 10), is_all_day: true };
        this.noteOpen = true;
    },

    editNote(event) {
        const p = event.extendedProps;
        this.note = {
            id: p.noteId,
            title: event.title,
            type: p.type === 'Reminder' ? 'reminder' : p.type === 'Deadline' ? 'deadline' : 'note',
            note: p.note ?? '',
            starts_at: event.start.toISOString().slice(0, 10),
            is_all_day: event.allDay,
        };
        this.noteOpen = true;
    },

    async saveNote() {
        if (!this.note.title.trim()) return;

        this.saving = true;
        const editing = this.note.id !== null;

        try {
            const response = await fetch(editing ? `${noteUrl}/${this.note.id}` : noteUrl, {
                method: editing ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    Accept: 'application/json',
                },
                body: JSON.stringify(this.note),
            });

            const body = await response.json();
            if (!response.ok) throw new Error(body.message ?? 'Gagal menyimpan catatan.');

            this.noteOpen = false;
            this.refresh();
            window.toast(body.message, 'success');
        } catch (error) {
            window.toast(error.message, 'error');
        } finally {
            this.saving = false;
        }
    },

    async deleteNote() {
        if (this.note.id === null || !confirm('Hapus catatan ini?')) return;

        this.saving = true;

        try {
            const response = await fetch(`${noteUrl}/${this.note.id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    Accept: 'application/json',
                },
            });

            if (!response.ok) throw new Error('Gagal menghapus catatan.');

            this.noteOpen = false;
            this.refresh();
            window.toast('Catatan dihapus.', 'success');
        } catch (error) {
            window.toast(error.message, 'error');
        } finally {
            this.saving = false;
        }
    },
}));

Alpine.start();
