<?php
/**
 * modules/printers/printers.php
 * Janus Printer Fleet & Telemetry Monitoring UI
 */
require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../auth_check.php';

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Printer Fleet | Janus</title>
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css">
<style>
:root {
  --p-card-bg: rgba(22, 27, 34, 0.75);
  --p-card-border: rgba(48, 54, 61, 0.75);
  --p-green: #3fb950;
  --p-red: #f85149;
  --p-yellow: #d29922;
  --p-blue: #58a6ff;
  --p-purple: #bc8cff;
  --p-text: #c9d1d9;
  --p-muted: #8b949e;
}

body.loggedin {
  background-color: #0d1117;
  color: var(--p-text);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

.printer-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.p-stat-card {
  background: var(--p-card-bg);
  border: 1px solid var(--p-card-border);
  border-radius: 12px;
  padding: 18px 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  backdrop-filter: blur(10px);
  box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.p-stat-icon {
  width: 46px;
  height: 46px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.4rem;
  flex-shrink: 0;
}

.p-stat-val {
  font-size: 1.6rem;
  font-weight: 700;
  line-height: 1.1;
}

.p-stat-lbl {
  font-size: 0.78rem;
  color: var(--p-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-top: 2px;
}

.printer-panel {
  background: var(--p-card-bg);
  border: 1px solid var(--p-card-border);
  border-radius: 12px;
  padding: 20px;
  backdrop-filter: blur(10px);
  box-shadow: 0 8px 30px rgba(0,0,0,0.3);
}

.printer-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 16px;
}

.printer-table th {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--p-muted);
  padding: 10px 14px;
  border-bottom: 1px solid var(--p-card-border);
  text-align: left;
}

.printer-table td {
  padding: 14px;
  border-bottom: 1px solid rgba(48, 54, 61, 0.4);
  font-size: 0.85rem;
  vertical-align: middle;
}

.printer-table tr:hover {
  background: rgba(56, 139, 253, 0.04);
}

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 0.75rem;
  font-weight: 600;
}

.status-online { background: rgba(63, 185, 80, 0.15); color: var(--p-green); border: 1px solid rgba(63, 185, 80, 0.3); }
.status-offline { background: rgba(139, 148, 158, 0.15); color: var(--p-muted); border: 1px solid rgba(139, 148, 158, 0.3); }
.status-jam { background: rgba(248, 81, 73, 0.15); color: var(--p-red); border: 1px solid rgba(248, 81, 73, 0.3); }
.status-toner { background: rgba(210, 153, 34, 0.15); color: var(--p-yellow); border: 1px solid rgba(210, 153, 34, 0.3); }

.toner-bar-bg {
  width: 90px;
  height: 8px;
  background: rgba(255,255,255,0.1);
  border-radius: 4px;
  overflow: hidden;
  display: inline-block;
  vertical-align: middle;
  margin-right: 6px;
}

.toner-bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s ease;
}

.action-btn {
  background: rgba(56, 139, 253, 0.1);
  color: var(--p-blue);
  border: 1px solid rgba(56, 139, 253, 0.3);
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 0.75rem;
  cursor: pointer;
  transition: all 0.2s;
}

.action-btn:hover {
  background: var(--p-blue);
  color: #fff;
}

.action-btn.del {
  background: rgba(248, 81, 73, 0.1);
  color: var(--p-red);
  border-color: rgba(248, 81, 73, 0.3);
}

.action-btn.del:hover {
  background: var(--p-red);
  color: #fff;
}

/* Modal styles */
.modal-content {
  background: #161b22;
  border: 1px solid var(--p-card-border);
  color: var(--p-text);
  border-radius: 12px;
}

.modal-header {
  border-bottom: 1px solid var(--p-card-border);
}

.modal-footer {
  border-top: 1px solid var(--p-card-border);
}

.form-control, .form-select {
  background: #0d1117;
  border: 1px solid var(--p-card-border);
  color: var(--p-text);
}

.form-control:focus, .form-select:focus {
  background: #0d1117;
  color: var(--p-text);
  border-color: var(--p-blue);
  box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.2);
}

/* Log Drawer */
.log-drawer {
  position: fixed;
  top: 0; right: -550px;
  width: 520px;
  height: 100vh;
  background: #161b22;
  border-left: 1px solid var(--p-card-border);
  box-shadow: -10px 0 40px rgba(0,0,0,0.6);
  z-index: 9999;
  transition: right 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  display: flex;
  flex-direction: column;
}

.log-drawer.open {
  right: 0;
}

.drawer-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--p-card-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: 18px 24px;
}
</style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; ?>
<?php include __DIR__ . '/../../sidebar.php'; ?>

<div id="main-content">
  <div class="container-fluid py-4">

    <!-- Title Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h2 class="mb-1"><i data-lucide="printer" class="icon-lucide text-primary"></i> Printer Fleet</h2>
        <p class="text-muted small mb-0">Centralized Printer Inventory, Status Telemetry, and Print Activity Monitoring</p>
      </div>
      <div>
        <button class="btn btn-primary btn-sm" onclick="openAddPrinterModal()">
          <i data-lucide="plus-circle" class="icon-lucide"></i> Add Printer
        </button>
      </div>
    </div>

    <!-- Stat Cards Grid -->
    <div class="printer-stats-grid">
      <div class="p-stat-card">
        <div class="p-stat-icon" style="background:rgba(88,166,255,0.12);color:var(--p-blue)">
          <i data-lucide="printer" class="icon-lucide"></i>
        </div>
        <div>
          <div class="p-stat-val" id="st-total">0</div>
          <div class="p-stat-lbl">Configured Printers</div>
        </div>
      </div>

      <div class="p-stat-card">
        <div class="p-stat-icon" style="background:rgba(63,185,80,0.12);color:var(--p-green)">
          <i data-lucide="check-circle-2" class="icon-lucide"></i>
        </div>
        <div>
          <div class="p-stat-val text-success" id="st-online">0</div>
          <div class="p-stat-lbl">Online &amp; Ready</div>
        </div>
      </div>

      <div class="p-stat-card">
        <div class="p-stat-icon" style="background:rgba(248,81,73,0.12);color:var(--p-red)">
          <i data-lucide="triangle-alert" class="icon-lucide"></i>
        </div>
        <div>
          <div class="p-stat-val text-danger" id="st-jams">0</div>
          <div class="p-stat-lbl">Paper Jams / Faults</div>
        </div>
      </div>

      <div class="p-stat-card">
        <div class="p-stat-icon" style="background:rgba(210,153,34,0.12);color:var(--p-yellow)">
          <i data-lucide="droplet" class="icon-lucide"></i>
        </div>
        <div>
          <div class="p-stat-val style='color:var(--p-yellow)'" id="st-lowtoner">0</div>
          <div class="p-stat-lbl">Low Toner Alerts</div>
        </div>
      </div>
    </div>

    <!-- Main Inventory Panel -->
    <div class="printer-panel">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i data-lucide="list" class="icon-lucide me-2"></i> Printer Inventory</h5>
        <div class="d-flex gap-2">
          <input type="text" id="printerSearch" class="form-control form-control-sm" placeholder="Filter printer name, IP, model..." onkeyup="filterPrintersTable()" style="width:240px">
          <button class="btn btn-outline-secondary btn-sm" onclick="loadPrinters()"><i data-lucide="refresh-cw" class="icon-lucide"></i></button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="printer-table">
          <thead>
            <tr>
              <th>Printer / Hostname</th>
              <th>IP Address</th>
              <th>Make / Vendor</th>
              <th>Model</th>
              <th>Location</th>
              <th>Status</th>
              <th>Toner Level</th>
              <th>Events Logged</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="printerTableBody">
            <tr>
              <td colspan="9" class="text-center text-muted py-5">Loading printer fleet...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Add / Edit Printer Modal -->
<div class="modal fade" id="printerModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle"><i data-lucide="printer" class="icon-lucide me-2"></i> Add Network Printer</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="printerForm">
          <input type="hidden" id="p-id" value="0">
          <div class="mb-3">
            <label class="form-label small">Printer Name / Hostname *</label>
            <input type="text" id="p-name" class="form-control form-control-sm" placeholder="e.g. HR-Floor2-HP-LaserJet" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label small">IP Address *</label>
              <input type="text" id="p-ip" class="form-control form-control-sm" placeholder="172.18.104.50" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label small">Vendor / Make</label>
              <select id="p-vendor" class="form-select form-select-sm">
                <option value="HP">HP</option>
                <option value="Ricoh">Ricoh</option>
                <option value="Canon">Canon</option>
                <option value="Xerox">Xerox</option>
                <option value="Lexmark">Lexmark</option>
                <option value="Kyocera">Kyocera</option>
                <option value="Brother">Brother</option>
                <option value="Epson">Epson</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label small">Model</label>
              <input type="text" id="p-model" class="form-control form-control-sm" placeholder="e.g. LaserJet Enterprise M608">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label small">Location / Dept</label>
              <input type="text" id="p-location" class="form-control form-control-sm" placeholder="e.g. Floor 2 - HR Room 204">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label small">SNMP Community String</label>
              <input type="text" id="p-community" class="form-control form-control-sm" value="public">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label small">SNMP Version</label>
              <select id="p-version" class="form-select form-select-sm">
                <option value="2c">v2c</option>
                <option value="1">v1</option>
                <option value="3">v3</option>
              </select>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="savePrinter()">Save Printer</button>
      </div>
    </div>
  </div>
</div>

<!-- Live Printer Logs Drawer -->
<div class="log-drawer" id="logDrawer">
  <div class="drawer-header">
    <div>
      <h6 class="mb-0" id="drawerPrinterName">Printer Logs</h6>
      <small class="text-muted" id="drawerPrinterIP">IP: -</small>
    </div>
    <button class="btn btn-sm btn-outline-secondary" onclick="closeLogDrawer()">✕</button>
  </div>
  <div class="drawer-body" id="drawerLogsBody">
    <div class="text-center text-muted py-5">Loading printer logs...</div>
  </div>
</div>

<script src="/js/bootstrap.bundle.min.js"></script>
<script>
let cachedPrinters = [];

async function loadPrinters() {
  const res = await fetch('printers_ajax.php?action=list');
  const data = await res.json();
  if (!data.ok) return;

  cachedPrinters = data.printers || [];
  
  // Render stats
  document.getElementById('st-total').textContent = data.stats.total;
  document.getElementById('st-online').textContent = data.stats.online;
  document.getElementById('st-jams').textContent = data.stats.jams;
  document.getElementById('st-lowtoner').textContent = data.stats.low_toner;

  renderPrintersTable(cachedPrinters);
}

function renderPrintersTable(printers) {
  const tbody = document.getElementById('printerTableBody');
  if (!printers.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-5">No printers configured yet. Click <strong>Add Printer</strong> above to get started.</td></tr>`;
    return;
  }

  tbody.innerHTML = printers.map(p => {
    let statusClass = 'status-online', statusLabel = '🟢 Online';
    if (p.status === 'offline') { statusClass = 'status-offline'; statusLabel = '⚪ Offline'; }
    else if (p.status === 'paper_jam') { statusClass = 'status-jam'; statusLabel = '🔴 Paper Jam'; }
    else if (p.status === 'toner_low' || (p.toner_level != null && p.toner_level < 20)) { statusClass = 'status-toner'; statusLabel = '🟡 Low Toner'; }

    const toner = p.toner_level != null ? p.toner_level : 100;
    let tonerColor = 'var(--p-green)';
    if (toner < 20) tonerColor = 'var(--p-red)';
    else if (toner < 40) tonerColor = 'var(--p-yellow)';

    return `
      <tr>
        <td><strong>${escHtml(p.name)}</strong></td>
        <td><code>${escHtml(p.ip)}</code></td>
        <td><span class="badge bg-secondary">${escHtml(p.vendor || 'HP')}</span></td>
        <td>${escHtml(p.model || '—')}</td>
        <td>${escHtml(p.location || '—')}</td>
        <td><span class="status-badge ${statusClass}">${statusLabel}</span></td>
        <td>
          <div class="toner-bar-bg"><div class="toner-bar-fill" style="width:${toner}%;background:${tonerColor}"></div></div>
          <strong>${toner}%</strong>
        </td>
        <td><span class="badge bg-dark">${p.total_events || 0} logs</span></td>
        <td>
          <button class="action-btn" title="Live Printer Logs" onclick="viewPrinterLogs('${escHtml(p.ip)}', '${escHtml(p.name)}')">📋 Logs</button>
          <button class="action-btn" title="Poll Status" onclick="pollPrinter(${p.id})">🔄 Poll</button>
          <button class="action-btn del" title="Delete" onclick="deletePrinter(${p.id}, '${escHtml(p.name)}')">🗑</button>
        </td>
      </tr>
    `;
  }).join('');
}

function filterPrintersTable() {
  const q = document.getElementById('printerSearch').value.toLowerCase().trim();
  if (!q) { renderPrintersTable(cachedPrinters); return; }
  const filtered = cachedPrinters.filter(p => 
    p.name.toLowerCase().includes(q) ||
    p.ip.toLowerCase().includes(q) ||
    (p.model && p.model.toLowerCase().includes(q)) ||
    (p.location && p.location.toLowerCase().includes(q))
  );
  renderPrintersTable(filtered);
}

function openAddPrinterModal() {
  document.getElementById('p-id').value = '0';
  document.getElementById('p-name').value = '';
  document.getElementById('p-ip').value = '';
  document.getElementById('p-vendor').value = 'HP';
  document.getElementById('p-model').value = '';
  document.getElementById('p-location').value = '';
  document.getElementById('modalTitle').innerHTML = '<i data-lucide="printer" class="icon-lucide me-2"></i> Add Network Printer';
  const modal = new bootstrap.Modal(document.getElementById('printerModal'));
  modal.show();
}

async function savePrinter() {
  const id = document.getElementById('p-id').value;
  const name = document.getElementById('p-name').value.trim();
  const ip = document.getElementById('p-ip').value.trim();
  const vendor = document.getElementById('p-vendor').value;
  const model = document.getElementById('p-model').value.trim();
  const location = document.getElementById('p-location').value.trim();
  const community = document.getElementById('p-community').value.trim();
  const version = document.getElementById('p-version').value;

  if (!name || !ip) { alert('Printer Name and IP address are required.'); return; }

  const fd = new FormData();
  fd.append('action', id === '0' ? 'add' : 'edit');
  if (id !== '0') fd.append('id', id);
  fd.append('name', name);
  fd.append('ip', ip);
  fd.append('vendor', vendor);
  fd.append('model', model);
  fd.append('location', location);
  fd.append('snmp_community', community);
  fd.append('snmp_version', version);

  const res = await fetch('printers_ajax.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    bootstrap.Modal.getInstance(document.getElementById('printerModal')).hide();
    loadPrinters();
  } else {
    alert('Error: ' + (data.error || 'Failed to save printer'));
  }
}

async function pollPrinter(id) {
  const fd = new FormData();
  fd.append('action', 'poll');
  fd.append('id', id);
  const res = await fetch('printers_ajax.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    loadPrinters();
  }
}

async function deletePrinter(id, name) {
  if (!confirm(`Delete printer "${name}" from inventory?`)) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);
  await fetch('printers_ajax.php', { method: 'POST', body: fd });
  loadPrinters();
}

async function viewPrinterLogs(ip, name) {
  document.getElementById('drawerPrinterName').textContent = name;
  document.getElementById('drawerPrinterIP').textContent = 'IP: ' + ip;
  document.getElementById('logDrawer').classList.add('open');

  const body = document.getElementById('drawerLogsBody');
  body.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin fa-2x mb-3"></i><br>Fetching printer logs...</div>';

  const fd = new FormData();
  fd.append('action', 'logs');
  fd.append('ip', ip);
  const res = await fetch('printers_ajax.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (!data.ok || !data.logs || !data.logs.length) {
    body.innerHTML = `<div class="text-center text-muted py-5"><i class="fas fa-check-circle text-success fa-2x mb-2"></i><br>No printer syslog events recorded yet for ${ip}.</div>`;
    return;
  }

  body.innerHTML = data.logs.map(l => `
    <div style="background:rgba(0,0,0,0.3);border:1px solid var(--p-card-border);border-radius:8px;padding:12px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <span class="badge bg-secondary">${l.action || 'EVENT'}</span>
        <span style="font-size:0.75rem;color:var(--p-muted)">${l.received_at}</span>
      </div>
      <div style="font-size:0.82rem;font-family:monospace;word-break:break-all">${escHtml(l.message)}</div>
    </div>
  `).join('');
}

function closeLogDrawer() {
  document.getElementById('logDrawer').classList.remove('open');
}

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', loadPrinters);
</script>
</body>
</html>
