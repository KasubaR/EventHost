import Chart from 'chart.js/auto';

/** @type {WeakMap<Element, Chart[]>} */
const chartInstancesByRoot = new WeakMap();

function prefersReducedMotion() {
    return (
        window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
    );
}

/**
 * @param {HTMLScriptElement | null} el
 * @returns {Record<string, unknown> | null}
 */
function readJsonPayload(el) {
    if (!el || el.textContent == null) {
        return null;
    }
    const raw = el.textContent.trim();
    if (!raw) {
        return null;
    }
    return JSON.parse(raw);
}

/**
 * @param {Element} root
 */
function destroyCharts(root) {
    const prev = chartInstancesByRoot.get(root);
    if (prev?.length) {
        prev.forEach((c) => c.destroy());
    }
    chartInstancesByRoot.delete(root);
}

/**
 * @param {HTMLCanvasElement} canvas
 * @returns {void}
 */
function teardownCanvas(canvas) {
    const existing = Chart.getChart(canvas);
    if (existing) {
        existing.destroy();
    }
}

/**
 * @param {Record<string, unknown>} payload
 * @param {Element} root
 */
function mountCharts(root, payload) {
    destroyCharts(root);
    const instances = [];
    const reducedMotion = prefersReducedMotion();
    const animation = reducedMotion ? false : undefined;

    const dailySeries = payload.daily_rsvps;
    if (Array.isArray(dailySeries)) {
        const wrap = root.querySelector('[data-analytics-chart="daily"]');
        const canvas = wrap?.querySelector('canvas');
        if (canvas instanceof HTMLCanvasElement) {
            teardownCanvas(canvas);
            const labels = dailySeries.map((row) => {
                const d = String(row.date ?? '');
                const parsed = new Date(`${d}T12:00:00`);
                return Number.isNaN(parsed.getTime())
                    ? d
                    : parsed.toLocaleDateString(undefined, {
                          month: 'short',
                          day: 'numeric',
                      });
            });
            const counts = dailySeries.map((row) => Number(row.count ?? 0));
            instances.push(
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: 'RSVPs',
                                data: counts,
                                backgroundColor: 'rgba(0, 206, 201, 0.72)',
                                borderRadius: 6,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { maxRotation: 0, autoSkip: true },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { precision: 0 },
                                grid: {
                                    color: 'rgba(26, 42, 74, 0.06)',
                                },
                            },
                        },
                    },
                }),
            );
        }
    }

    const statusRows = payload.status_chart;
    if (Array.isArray(statusRows) && statusRows.length > 0) {
        const wrap = root.querySelector('[data-analytics-chart="status"]');
        const canvas = wrap?.querySelector('canvas');
        if (canvas instanceof HTMLCanvasElement) {
            teardownCanvas(canvas);
            const colorsByKey = {
                accepted: '#48c78e',
                declined: '#e74c3c',
                maybe: '#f39c12',
            };
            instances.push(
                new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        labels: statusRows.map((r) => String(r.label ?? '')),
                        datasets: [
                            {
                                data: statusRows.map((r) => Number(r.count ?? 0)),
                                backgroundColor: statusRows.map(
                                    (r) =>
                                        colorsByKey[String(r.key ?? '')] ??
                                        '#6c5ce7',
                                ),
                                borderWidth: 0,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { boxWidth: 12 },
                            },
                        },
                    },
                }),
            );
        }
    }

    const groupRows = payload.group_breakdown;
    if (Array.isArray(groupRows) && groupRows.length > 0) {
        const wrap = root.querySelector('[data-analytics-chart="groups"]');
        const canvas = wrap?.querySelector('canvas');
        if (canvas instanceof HTMLCanvasElement) {
            teardownCanvas(canvas);
            instances.push(
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: groupRows.map((r) => String(r.label ?? '')),
                        datasets: [
                            {
                                label: 'Guests',
                                data: groupRows.map((r) => Number(r.count ?? 0)),
                                backgroundColor: 'rgba(108, 92, 231, 0.75)',
                                borderRadius: 6,
                            },
                        ],
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        animation,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: { precision: 0 },
                                grid: {
                                    color: 'rgba(26, 42, 74, 0.06)',
                                },
                            },
                            y: { grid: { display: false } },
                        },
                    },
                }),
            );
        }
    }

    chartInstancesByRoot.set(root, instances);
}

function boot() {
    document.querySelectorAll('[data-analytics-root]').forEach((root) => {
        if (!(root instanceof HTMLElement)) {
            return;
        }
        const jsonId = root.dataset.analyticsJsonId;
        if (!jsonId || root.dataset.analyticsChartsReady === '1') {
            return;
        }
        const script = document.getElementById(jsonId);
        if (!(script instanceof HTMLScriptElement)) {
            return;
        }
        try {
            const payload = readJsonPayload(script);
            if (!payload || typeof payload !== 'object') {
                return;
            }
            mountCharts(root, payload);
            root.dataset.analyticsChartsReady = '1';
        } catch (err) {
            console.error('[analytics-charts] Failed to mount charts', err);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
