import ApexCharts from 'apexcharts';

/**
 * Shared ApexCharts theme that adapts to dark/light mode.
 * Palette leads with the brand violet, then complementary hues that stay
 * distinguishable for colour-blind viewers and in both themes.
 */
export function chartTheme() {
    const dark = document.documentElement.classList.contains('dark');

    return {
        palette: ['#8b5cf6', '#06b6d4', '#22c55e', '#f59e0b', '#ec4899', '#3b82f6', '#ef4444', '#14b8a6'],
        fg: dark ? '#94a3b8' : '#64748b',
        grid: dark ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)',
        tooltipTheme: dark ? 'dark' : 'light',
    };
}

/** Brand colours per social platform — keeps charts/badges consistent. */
export const platformColors = {
    instagram: '#e1306c',
    facebook: '#1877f2',
    tiktok: '#00c4bd',
    youtube: '#ff0000',
    twitter: '#0f172a',
    linkedin: '#0a66c2',
    whatsapp: '#25d366',
};

function baseOptions() {
    const t = chartTheme();

    return {
        chart: {
            fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
            foreColor: t.fg,
            toolbar: { show: false },
            animations: {
                enabled: true,
                easing: 'easeinout',
                speed: 600,
                animateGradually: { enabled: true, delay: 80 },
            },
        },
        colors: t.palette,
        grid: { borderColor: t.grid, strokeDashArray: 4, padding: { left: 8, right: 8 } },
        dataLabels: { enabled: false },
        tooltip: { theme: t.tooltipTheme },
        legend: { labels: { colors: t.fg }, fontWeight: 500, markers: { radius: 4 } },
        stroke: { curve: 'smooth', width: 2.5 },
        states: { hover: { filter: { type: 'darken', value: 0.92 } } },
    };
}

function deepMerge(a, b) {
    const out = { ...a };

    for (const k in b) {
        out[k] = b[k] && typeof b[k] === 'object' && !Array.isArray(b[k])
            ? deepMerge(a[k] || {}, b[k])
            : b[k];
    }

    return out;
}

/**
 * Create a themed ApexChart that re-skins itself when dark mode toggles.
 *
 * @param {string|HTMLElement} el  selector or element
 * @param {object} options         ApexCharts options (merged over the theme)
 */
export function makeChart(el, options) {
    const node = typeof el === 'string' ? document.querySelector(el) : el;
    if (!node) return null;

    const chart = new ApexCharts(node, deepMerge(baseOptions(), options));
    chart.render();

    const reskin = () => {
        const t = chartTheme();
        chart.updateOptions(
            {
                chart: { foreColor: t.fg },
                colors: options.colors ?? t.palette,
                grid: { borderColor: t.grid },
                tooltip: { theme: t.tooltipTheme },
                legend: { labels: { colors: t.fg } },
            },
            false,
            false
        );
    };

    window.addEventListener('theme-changed', reskin);

    return chart;
}
