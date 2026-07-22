// GLOBAL VARIABLES

// Chart.js global HUD defaults
if (window.Chart) {
    Chart.defaults.color = '#8592a3';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
    Chart.defaults.font.family = "'JetBrains Mono', monospace";
    Chart.defaults.font.size = 11;
}

// CRITICAL: Chart instances registry to prevent duplicates
const chartInstances = {};

function destroyChart(chartId) {
    if (chartInstances[chartId]) {
        chartInstances[chartId].destroy();
        delete chartInstances[chartId];
    }
}

function createChart(canvasId, config) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (canvas) {
        chartInstances[canvasId] = new Chart(canvas, config);
    }
}

// Count-up animation for stat readouts
function animateStatValues(container) {
    (container || document).querySelectorAll('.stat-value[data-raw]').forEach(el => {
        const raw = parseFloat(el.getAttribute('data-raw'));
        if (isNaN(raw)) return;
        const suffix = el.getAttribute('data-suffix') || '';
        const duration = 600;
        const start = performance.now();
        function step(now) {
            const p = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(raw * eased).toLocaleString() + suffix;
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}

// -----------------------------------------------------------------
// REPORT CATALOG — drives the two-tier nav (category tabs -> chips)
// -----------------------------------------------------------------
const REPORT_CATALOG = {
    network: { label: 'Network Analysis', icon: 'fa-network-wired', reports: [
        { type: 'traffic', label: 'Traffic Analysis', icon: 'fa-exchange-alt' },
        { type: 'applications', label: 'Applications', icon: 'fa-layer-group' },
        { type: 'bandwidth', label: 'Bandwidth', icon: 'fa-chart-area' },
    ]},
    security: { label: 'Security & Threats', icon: 'fa-shield-virus', reports: [
        { type: 'security_analysis', label: 'MITRE ATT&CK', icon: 'fa-shield-alt' },
        { type: 'threat_hunting', label: 'Threat Hunting', icon: 'fa-crosshairs' },
        { type: 'bruteforce', label: 'Brute Force', icon: 'fa-user-lock' },
        { type: 'anomaly', label: 'Anomaly Detection', icon: 'fa-exclamation-triangle' },
    ]},
    access: { label: 'Access & Authentication', icon: 'fa-user-shield', reports: [
        { type: 'remote_access', label: 'Remote Access', icon: 'fa-door-open' },
        { type: 'user_activity', label: 'User Activity', icon: 'fa-users' },
    ]},
    attacks: { label: 'Attack Detection', icon: 'fa-bomb', reports: [
        { type: 'dns_attacks', label: 'DNS Attacks', icon: 'fa-server' },
        { type: 'ddos', label: 'DDoS', icon: 'fa-radiation' },
        { type: 'malware', label: 'Malware', icon: 'fa-bug' },
    ]},
    config: { label: 'Configuration & Compliance', icon: 'fa-cog', reports: [
        { type: 'config_changes', label: 'Config Changes', icon: 'fa-wrench' },
        { type: 'compliance', label: 'Compliance', icon: 'fa-clipboard-check' },
    ]},
    assets: { label: 'Asset & Inventory', icon: 'fa-sitemap', reports: [
        { type: 'asset_discovery', label: 'Asset Discovery', icon: 'fa-radar' },
        { type: 'vulnerability', label: 'Vulnerabilities', icon: 'fa-shield-alt' },
        { type: 'endpoint_activity', label: 'Endpoint Activity', icon: 'fa-desktop' },
    ]},
};

function categoryOf(type) {
    for (const key in REPORT_CATALOG) {
        if (REPORT_CATALOG[key].reports.some(r => r.type === type)) return key;
    }
    return 'network';
}

let currentCategory = categoryOf(currentType);

function renderCategoryTabs() {
    const el = document.getElementById('categoryTabs');
    el.innerHTML = Object.keys(REPORT_CATALOG).map(key => {
        const cat = REPORT_CATALOG[key];
        return `<button type="button" class="category-tab ${key === currentCategory ? 'active' : ''}" data-cat="${key}">
                    <i class="fas ${cat.icon}"></i> ${cat.label}
                </button>`;
    }).join('');
    el.querySelectorAll('.category-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            currentCategory = btn.getAttribute('data-cat');
            renderCategoryTabs();
            const firstReport = REPORT_CATALOG[currentCategory].reports[0];
            selectReport(firstReport.type);
        });
    });
}

function renderReportChips() {
    const el = document.getElementById('reportChips');
    el.innerHTML = REPORT_CATALOG[currentCategory].reports.map(r => {
        return `<button type="button" class="report-chip ${r.type === currentType ? 'active' : ''}" data-type="${r.type}">
                    <i class="fas ${r.icon}"></i> ${r.label}
                </button>`;
    }).join('');
    el.querySelectorAll('.report-chip').forEach(btn => {
        btn.addEventListener('click', () => selectReport(btn.getAttribute('data-type')));
    });
}

function selectReport(type) {
    currentType = type;
    currentCategory = categoryOf(type);
    renderCategoryTabs();
    renderReportChips();
    loadReportContent(currentType);
    const url = new URL(window.location);
    url.searchParams.set('type', currentType);
    window.history.pushState({}, '', url);
}

function renderRangePills() {
    document.querySelectorAll('.range-pill').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-range') === currentRange);
        btn.addEventListener('click', () => {
            currentRange = btn.getAttribute('data-range');
            applyFilters();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Auto-refresh every 5 minutes
    setInterval(() => {
        const btn = document.getElementById('refreshBtn');
        if (btn) btn.classList.add('spinning');
        loadReportContent(currentType);
        setTimeout(() => { if (btn) btn.classList.remove('spinning'); }, 800);
    }, 5 * 60 * 1000);
    renderCategoryTabs();
    renderReportChips();
    renderRangePills();
    loadReportContent(currentType);
    // Auto-refresh the active report every 5 minutes (matches the agent check-in cadence)
    setInterval(() => {
        const btn = document.getElementById('refreshBtn');
        if (btn) btn.classList.add('spinning');
        loadReportContent(currentType);
        setTimeout(() => { if (btn) btn.classList.remove('spinning'); }, 800);
    }, 5 * 60 * 1000);
});

function setDeviceFilter(val) {
    document.getElementById('deviceFilter').value = val;
    applyFilters();
}

function applyFilters() {
    const device = document.getElementById('deviceFilter').value;
    const url = new URL(window.location);
    url.searchParams.set('type', currentType);
    url.searchParams.set('range', currentRange);
    url.searchParams.set('device', device);
    window.location.href = url.toString();
}

async function loadReportContent(type, extraParams = '') {
    const contentDiv = document.getElementById('reportContent');
    contentDiv.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted" style="font-family: var(--font-mono); font-size:0.8rem; letter-spacing:0.05em;">LOADING ${type.replace(/_/g, ' ').toUpperCase()}&hellip;</p>
        </div>
    `;

    Object.keys(chartInstances).forEach(chartId => destroyChart(chartId));

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            range: currentRange
        });

        if (extraParams) {
            const extra = new URLSearchParams(extraParams);
            extra.forEach((value, key) => params.append(key, value));
        }

        const url = `ss/${type}.php?${params.toString()}`;
        console.log('Loading:', url);

        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`Server error: ${response.status}`);
        }

        const html = await response.text();
        contentDiv.innerHTML = html;
        animateStatValues(contentDiv);

        const scripts = contentDiv.querySelectorAll('script');
        scripts.forEach(script => {
            const newScript = document.createElement('script');
            newScript.textContent = script.textContent;
            document.head.appendChild(newScript);
            document.head.removeChild(newScript);
        });

    } catch (error) {
        console.error('Load error:', error);
        contentDiv.innerHTML = `
            <div class="alert alert-danger">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                <strong>Error loading report:</strong> ${error.message}
            </div>
        `;
    }
}

async function drillDownIP(ip) {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('drillDownModal'));
    const modalTitle = document.getElementById('drillDownModalLabel');
    const modalBody = document.getElementById('drillDownContent');

    modalTitle.innerHTML = `<i data-lucide="network" class="icon-lucide"></i> IP Analysis: ${ip}`;
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Analyzing ${ip}...</p>
        </div>
    `;

    modal.show();
    Object.keys(chartInstances).filter(id => id.startsWith('modal-')).forEach(destroyChart);

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            drilldown_ip: ip
        });

        const response = await fetch(`ss/ip_drilldown.php?${params.toString()}`);
        if (!response.ok) throw new Error(`Failed: ${response.status}`);

        const html = await response.text();
        modalBody.innerHTML = html;

        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(script => {
            try { eval(script.textContent); } catch (e) { console.error('Script error:', e); }
        });

    } catch (error) {
        console.error('Drill-down error:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                Error loading IP details: ${error.message}
            </div>
        `;
    }
}

async function drillDownTechnique(techniqueId, techniqueName) {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('drillDownModal'));
    const modalTitle = document.getElementById('drillDownModalLabel');
    const modalBody = document.getElementById('drillDownContent');

    modalTitle.innerHTML = `<i data-lucide="shield-virus" class="icon-lucide"></i> ${techniqueId}: ${techniqueName}`;
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Loading technique details...</p>
        </div>
    `;

    modal.show();
    Object.keys(chartInstances).filter(id => id.startsWith('modal-')).forEach(destroyChart);

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            technique: techniqueId
        });

        const response = await fetch(`ss/mitre_drilldown.php?${params.toString()}`);
        if (!response.ok) throw new Error(`Failed: ${response.status}`);

        const html = await response.text();
        modalBody.innerHTML = html;

        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(script => {
            try { eval(script.textContent); } catch (e) { console.error('Script error:', e); }
        });

    } catch (error) {
        console.error('Drill-down error:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                Error: ${error.message}
            </div>
        `;
    }
}

function refreshReport() { loadReportContent(currentType); }
function exportReport() { window.print(); }

function exportDrillDown() {
    const content = document.getElementById('drillDownContent');
    const title = document.getElementById('drillDownModalLabel').textContent;
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write('<html><head><title>' + title + '</title>');
    printWindow.document.write('<link rel="stylesheet" href="/css/theme.css">
<link rel="stylesheet" href="/css/pages/reports.css">');
    printWindow.document.write('</head><body><h2>' + title + '</h2>');
    printWindow.document.write(content.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

function scheduledReports() {
    alert('Scheduled Reports:\n\nConfigure automated report generation and delivery.\n\nContact your administrator to enable this feature.');
}

document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey) {
        if (e.key === 'r') { e.preventDefault(); refreshReport(); }
        else if (e.key === 'p') { e.preventDefault(); exportReport(); }
    }
});

window.drillDownIP = drillDownIP;
window.drillDownTechnique = drillDownTechnique;
window.loadReportContent = loadReportContent;
window.createChart = createChart;
window.destroyChart = destroyChart;
window.animateStatValues = animateStatValues;

// Safety net: force-clean any stuck backdrop / body scroll-lock after this
// shared modal closes, no matter which function opened it.
document.getElementById('drillDownModal').addEventListener('hidden.bs.modal', () => {
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
});
