document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.initOfpCustomSelects === 'function') {
        window.initOfpCustomSelects();
    }

    const canvas = document.getElementById('monthlyPerformanceChart');
    if (!canvas) {
        return;
    }

    let reportChart   = null;
    let currentMetric = document.getElementById('ofp-report-metric')?.value || 'leads';
    let currentYear   = document.getElementById('ofp-report-year')?.value || new Date().getFullYear().toString();

    const palette = {
        leads:        { c1: 'rgba(59, 130, 246, 1)',  c1_bg: 'rgba(59, 130, 246, 0.45)', c2: 'rgba(16, 185, 129, 1)',  c2_bg: 'rgba(16, 185, 129, 0.45)' },
        sales:        { c1: 'rgba(139, 92, 246, 1)',  c1_bg: 'rgba(139, 92, 246, 0.45)', c2: 'rgba(16, 185, 129, 1)',  c2_bg: 'rgba(16, 185, 129, 0.45)' },
        rent:         { c1: 'rgba(14, 165, 233, 1)',  c1_bg: 'rgba(14, 165, 233, 0.45)', c2: 'rgba(245, 158, 11, 1)',  c2_bg: 'rgba(245, 158, 11, 0.45)' },
        inspections:  { c1: 'rgba(99, 102, 241, 1)',  c1_bg: 'rgba(99, 102, 241, 0.45)', c2: 'rgba(20, 184, 166, 1)',  c2_bg: 'rgba(20, 184, 166, 0.45)' },
        comms_spend:  { c1: 'rgba(236, 72, 153, 1)',  c1_bg: 'rgba(236, 72, 153, 0.45)', c2: 'rgba(244, 63, 94, 1)',   c2_bg: 'rgba(244, 63, 94, 0.45)' },
        transactions: { c1: 'rgba(16, 185, 129, 1)',  c1_bg: 'rgba(16, 185, 129, 0.45)', c2: 'rgba(59, 130, 246, 1)',  c2_bg: 'rgba(59, 130, 246, 0.45)' }
    };

    function formatNumber(val, isCurrency) {
        if (isCurrency) {
            return '₦' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }
        return Number(val || 0).toLocaleString();
    }

    function updateKPIs(kpis) {
        if (!kpis || !kpis.length) return;
        kpis.forEach(function(kpi, idx) {
            const card  = document.getElementById('ofp-kpi-card-' + idx);
            const title = document.getElementById('ofp-kpi-title-' + idx);
            const val   = document.getElementById('ofp-kpi-val-' + idx);
            const badge = document.getElementById('ofp-kpi-badge-' + idx);
            if (card && title && val && badge) {
                title.textContent = kpi.title;
                val.textContent = kpi.value;
                badge.className = 'ofp-kpi-badge badge-' + (kpi.color || 'blue');
                badge.innerHTML = '<span>' + kpi.badge + '</span>';
            }
        });
    }

    function updateHeading(metric, year) {
        const heading = document.getElementById('ofp-chart-heading');
        const metricSelect = document.getElementById('ofp-report-metric');
        if (heading && metricSelect) {
            const opt = metricSelect.options[metricSelect.selectedIndex];
            const label = opt ? opt.text : 'Performance';
            heading.textContent = label + ' (' + year + ')';
        }
    }

    function renderChartData(data, metric, year) {
        if (!data || typeof Chart === 'undefined') return;

        const colors = palette[metric] || palette.leads;
        const isCurrency = data.format === 'currency';

        updateKPIs(data.kpis);
        updateHeading(metric, year || currentYear);

        const ctx2d = canvas.getContext('2d');
        const g1 = ctx2d.createLinearGradient(0, 0, 0, 300);
        g1.addColorStop(0, colors.c1);
        g1.addColorStop(1, colors.c1_bg);

        const g2 = ctx2d.createLinearGradient(0, 0, 0, 300);
        g2.addColorStop(0, colors.c2);
        g2.addColorStop(1, colors.c2_bg);

        const s1 = data.series1 || { label: 'Metric A', data: [] };
        const s2 = data.series2 || { label: 'Metric B', data: [] };

        const chartConfig = {
            type: 'bar',
            data: {
                labels: data.labels || [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ],
                datasets: [
                    {
                        label: s1.label,
                        data: s1.data,
                        backgroundColor: g1,
                        borderColor: colors.c1,
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.6,
                        categoryPercentage: 0.75
                    },
                    {
                        label: s2.label,
                        data: s2.data,
                        backgroundColor: g2,
                        borderColor: colors.c2,
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.6,
                        categoryPercentage: 0.75
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 350
                },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            color: '#9ca3af',
                            usePointStyle: true,
                            boxWidth: 8,
                            font: { size: 12, weight: '500' }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#111827',
                        titleColor: '#f9fafb',
                        bodyColor: '#e5e7eb',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: true,
                        callbacks: {
                            label: function(item) {
                                const label = item.dataset.label || '';
                                const val = item.raw || 0;
                                const shouldCurrency = isCurrency || (metric === 'comms_spend' && item.datasetIndex === 1);
                                return label + ': ' + formatNumber(val, shouldCurrency);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#9ca3af', font: { size: 12 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        border: { display: false },
                        ticks: {
                            color: '#9ca3af',
                            font: { size: 11 },
                            padding: 8,
                            callback: function(value) {
                                if (isCurrency) {
                                    if (value >= 1000000000) return '₦' + (value / 1000000000).toFixed(1) + 'B';
                                    if (value >= 1000000) return '₦' + (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000) return '₦' + (value / 1000).toFixed(0) + 'k';
                                    return '₦' + value;
                                }
                                if (value >= 1000) return (value / 1000).toFixed(0) + 'k';
                                return Number.isInteger(value) ? value : '';
                            }
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        };

        if (reportChart) {
            reportChart.destroy();
        }
        reportChart = new Chart(canvas, chartConfig);
    }

    function loadChart(metric, year) {
        const loading = document.getElementById('ofp-chart-loading');
        if (loading) loading.style.display = 'inline-flex';

        if (typeof ofpClientData === 'undefined') {
            if (loading) loading.style.display = 'none';
            console.error('ofpClientData is not defined.');
            return;
        }

        const body = new URLSearchParams();
        body.append('action', 'ofp_reports_chart_data');
        body.append('nonce', ofpClientData.nonce);
        body.append('metric', metric);
        body.append('year', year);

        fetch(ofpClientData.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (loading) loading.style.display = 'none';
                if (!res.success || !res.data) {
                    console.error('Failed to load chart data:', res);
                    return;
                }
                renderChartData(res.data, metric, year);
            })
            .catch(function(err) {
                if (loading) loading.style.display = 'none';
                console.error('OFP report chart fetch error:', err);
            });
    }

    function initChart() {
        if (typeof Chart === 'undefined') {
            let retries = 0;
            const timer = setInterval(function() {
                retries++;
                if (typeof Chart !== 'undefined') {
                    clearInterval(timer);
                    startChart();
                } else if (retries > 20) {
                    clearInterval(timer);
                    console.error('Chart.js failed to load.');
                }
            }, 50);
            return;
        }
        startChart();
    }

    function startChart() {
        // Metric dropdown change handler
        const metricSelect = document.getElementById('ofp-report-metric');
        if (metricSelect) {
            metricSelect.addEventListener('change', function() {
                currentMetric = this.value || 'leads';
                loadChart(currentMetric, currentYear);
            });
        }

        // Year select handler
        const yearSelect = document.getElementById('ofp-report-year');
        if (yearSelect) {
            yearSelect.addEventListener('change', function() {
                currentYear = this.value || new Date().getFullYear().toString();
                loadChart(currentMetric, currentYear);
            });
        }

        // Use embedded initial data if matching default selections
        if (window.ofpReportsInitialData && 
            window.ofpReportsInitialData.metric === currentMetric && 
            String(window.ofpReportsInitialData.year) === String(currentYear)) {
            renderChartData(window.ofpReportsInitialData, currentMetric, currentYear);
        } else {
            loadChart(currentMetric, currentYear);
        }
    }

    initChart();
});
