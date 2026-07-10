<?php
session_start();
require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../auth_check.php';

$theme     = $_COOKIE['theme'] ?? 'dark';
$user_role = $_SESSION['role'] ?? 'analyst';
$is_admin  = in_array($user_role, ['admin','manager']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Janus – IPAM</title>
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css">
<style>
#main-content{margin-left:260px;transition:margin-left .3s;padding-top:70px}
@media(max-width:768px){#main-content{margin-left:0}}

/* Row states */
.row-active  {background:rgba(22,163,74,.07) !important}
.row-inactive{background:rgba(107,114,128,.07)!important}
.row-reserved{background:rgba(234,179,8,.07) !important}
.row-spoofing{background:rgba(239,68,68,.2)  !important;animation:blink-row 1.1s infinite}
.row-changed {background:rgba(249,115,22,.12)!important;animation:pulse-row 2s infinite}
.row-unacked {border-left:3px solid #f97316 !important}
@keyframes blink-row{0%,100%{opacity:1}50%{opacity:.38}}
@keyframes pulse-row{0%,100%{opacity:1}50%{opacity:.6}}

/* Stats */
.stat-card{transition:transform .18s,box-shadow .18s;cursor:default;border-radius:10px}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 6px 22px rgba(0,0,0,.22)}
.stat-num{font-size:2rem;font-weight:800;line-height:1}

/* Subnet pills */
.sn-pill{cursor:pointer;border-radius:8px;transition:.2s;border:1px solid transparent}
.sn-pill:hover{border-color:#3b82f6;background:rgba(59,130,246,.08)}
.sn-pill.active-sn{border-color:#3b82f6 !important;background:rgba(59,130,246,.14)}
.sn-iface-badge{font-size:.68rem;padding:1px 6px;border-radius:6px}

/* Countdown */
.cdring{width:36px;height:36px}
.cdring circle{fill:none;stroke-width:3}
.cdring .bg  {stroke:#e5e7eb}
.cdring .fill{stroke:#22c55e;stroke-linecap:round;transform:rotate(-90deg);transform-origin:center;transition:stroke-dashoffset 1s linear}
[data-theme=dark] .cdring .bg{stroke:rgba(255,255,255,.12)}

/* Util bars */
.util-bar{height:6px;border-radius:3px;background:#e5e7eb;overflow:hidden}
.util-fill{height:100%;transition:width .7s}
[data-theme=dark] .util-bar{background:rgba(255,255,255,.1)}

/* Table */
.ip-cell {font-family:'Courier New',monospace;font-weight:700}
.mac-cell{font-family:'Courier New',monospace;font-size:.82rem}
.note-cell{max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ping-btn{font-size:.7rem;padding:2px 7px;border-radius:7px;cursor:pointer;user-select:none;min-width:40px;text-align:center}

/* Toast */
#toastStack{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:.35rem}

/* Subnet panel */
.subnet-row{border-left:3px solid #3b82f6;transition:.2s}
.subnet-row:hover{background:rgba(59,130,246,.05)}
.subnet-scan-badge{font-size:.68rem}

/* Scan progress */
.scan-bar{height:4px;border-radius:3px;overflow:hidden}

/* Card headers */
.ch-dark{background:#0f172a;color:#fff}

/* History drilldown */
.timeline-item{position:relative;padding:0 0 18px 32px;border-left:2px solid rgba(59,130,246,.25)}
.timeline-item:last-child{padding-bottom:0;border-left:2px solid transparent}
.timeline-dot{position:absolute;left:-9px;top:3px;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.55rem;font-weight:800;color:#fff}
.tl-spoof  {background:#ef4444}
.tl-mac    {background:#f59e0b}
.tl-offline{background:#6b7280}
.tl-online {background:#22c55e}
.tl-new    {background:#3b82f6}
.tl-info   {background:#8b5cf6}
.timeline-time{font-size:.72rem;color:#9ca3af}
.timeline-note{font-size:.82rem}
.tl-acked{opacity:.55}

/* Clickable alert rows */
.row-changed td, .row-spoofing td {cursor:pointer}
.row-changed:hover, .row-spoofing:hover{filter:brightness(1.1)}
.drill-hint{font-size:.65rem;color:#f97316;font-style:italic}

/* Improved subnet pills */
.sn-pill .sn-bar{height:3px;border-radius:2px;background:rgba(255,255,255,.1);margin-top:4px;overflow:hidden}
.sn-pill .sn-bar-fill{height:100%;border-radius:2px;transition:width .5s}
</style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; include __DIR__ . '/../../sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid py-4">

<!-- ══ HEADER ══ -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-network-wired me-2 text-primary"></i>IP Address Management</h2>
        <p class="text-muted small mb-0">
            <i class="fas fa-satellite-dish text-success me-1"></i>ARP + ICMP discovery &nbsp;|&nbsp;
            <i class="fas fa-skull-crossbones text-danger me-1"></i>MAC spoofing detection &nbsp;|&nbsp;
            <i class="fas fa-route text-info me-1"></i>Auto NIC selection &nbsp;|&nbsp;
            <i class="fas fa-clock text-warning me-1"></i>Scheduled auto-scan
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button id="btnBulkAck" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-check-double me-1"></i>Clear All Alerts
        </button>
        <button id="btnExport" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-file-csv me-1"></i>Export CSV
        </button>
        <a href="/modules/ipam/ipam_log.php" class="btn btn-sm btn-outline-info">
            <i class="fas fa-history me-1"></i>Change Log
        </a>
        <?php if ($is_admin): ?>
        <button id="btnAddIP" class="btn btn-sm btn-primary">
            <i class="fas fa-plus me-1"></i>Add IP
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ══ STATS ══ -->
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4 col-6">
        <div class="card stat-card border-0 text-center py-3 shadow-sm" style="background:#1e40af;color:#fff">
            <div class="stat-num" id="sTotal">—</div><div class="small mt-1">Total IPs</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="card stat-card border-0 text-center py-3 shadow-sm" style="background:#15803d;color:#fff">
            <div class="stat-num" id="sActive">—</div><div class="small mt-1">Online</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="card stat-card border-0 text-center py-3 shadow-sm" style="background:#374151;color:#fff">
            <div class="stat-num" id="sInactive">—</div><div class="small mt-1">Offline</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="card stat-card border-0 text-center py-3 shadow-sm" style="background:#92400e;color:#fff">
            <div class="stat-num" id="sReserved">—</div><div class="small mt-1">Reserved</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="card stat-card border-0 text-center py-3 shadow-sm" style="background:#991b1b;color:#fff">
            <div class="stat-num" id="sAlerts">—</div><div class="small mt-1">Alerts</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="card stat-card border-0 text-center py-3 shadow-sm" style="background:#5b21b6;color:#fff">
            <div class="stat-num" id="sNew">—</div><div class="small mt-1">New Today</div>
        </div>
    </div>
</div>

<!-- ══ MAIN LAYOUT ══ -->
<div class="row g-4">

    <!-- LEFT PANEL -->
    <div class="col-xl-3 col-lg-4">

        <!-- Tabs: Scanner | Subnets -->
        <ul class="nav nav-tabs mb-0" id="leftTabs">
            <li class="nav-item">
                <a class="nav-link active small py-2" data-bs-toggle="tab" href="#tabScanner">
                    <i class="fas fa-satellite-dish me-1"></i>Scanner
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link small py-2" data-bs-toggle="tab" href="#tabSubnets">
                    <i class="fas fa-project-diagram me-1"></i>Subnets
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link small py-2" data-bs-toggle="tab" href="#tabUtil">
                    <i class="fas fa-tachometer-alt me-1"></i>Util
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link small py-2" data-bs-toggle="tab" href="#tabNat"
                   onclick="loadNatMap()">
                    <i class="fas fa-random me-1"></i>NAT/VIP
                </a>
            </li>
        </ul>

        <div class="tab-content border border-top-0 rounded-bottom shadow-sm mb-3"
             style="background:var(--card-bg,#fff)">

            <!-- ─ SCANNER TAB ─ -->
            <div class="tab-pane fade show active p-3" id="tabScanner">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small fw-semibold">Select Subnet</span>
                    <span id="autoScanBadge" class="badge bg-secondary" style="font-size:.68rem">Manual</span>
                </div>
                <div id="subnetPills" class="d-flex flex-column gap-1 mb-2">
                    <!-- filled by JS -->
                    <div class="text-muted small text-center py-2">
                        <i class="fas fa-spinner fa-spin me-1"></i>Loading…
                    </div>
                </div>
                <input id="scanCIDR" class="form-control form-control-sm mb-2"
                       placeholder="Or type CIDR: 10.0.0.0/24">
                <div class="d-grid gap-2 mb-2">
                    <button id="btnScan" class="btn btn-success btn-sm">
                        <i class="fas fa-search-location me-1"></i>Scan Now
                    </button>
                    <button id="btnScanAll" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-globe me-1"></i>Scan All Enabled
                    </button>
                </div>
                <div class="progress scan-bar d-none mb-1" id="scanBar">
                    <div id="scanPrg" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%"></div>
                </div>
                <div id="scanTxt" class="text-muted small text-center" style="min-height:1.1em;font-size:.78rem"></div>

                <hr class="my-3">

                <!-- Auto-scan -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small fw-semibold">Auto-Scan</span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="autoToggle">
                    </div>
                </div>
                <select id="autoInterval" class="form-select form-select-sm mb-2">
                    <option value="300">Every 5 min</option>
                    <option value="600" selected>Every 10 min</option>
                    <option value="1800">Every 30 min</option>
                    <option value="3600">Every 1 hour</option>
                </select>
                <div id="cdArea" class="d-none d-flex align-items-center gap-2">
                    <svg class="cdring" viewBox="0 0 36 36">
                        <circle class="bg" cx="18" cy="18" r="15.9"/>
                        <circle class="fill" id="cdRing" cx="18" cy="18" r="15.9"
                                stroke-dasharray="100 100" stroke-dashoffset="0"/>
                    </svg>
                    <span class="small text-muted">Next: <strong id="cdTxt">—</strong></span>
                </div>

                <hr class="my-3">

                <!-- Scan stats -->
                <div class="small">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Last scan</span><span id="infoTime">Never</span>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Duration</span><span id="infoDur">—</span>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Interface</span><span id="infoIface">—</span>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Found</span><span id="infoFound">—</span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Changes</span><span id="infoChanges">—</span>
                    </div>
                </div>
            </div>

            <!-- ─ SUBNETS TAB ─ -->
            <div class="tab-pane fade p-3" id="tabSubnets">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="small fw-semibold">Registered Subnets</span>
                    <button class="btn btn-sm btn-primary py-0 px-2" id="btnAddSubnet">
                        <i class="fas fa-plus fa-xs"></i>
                    </button>
                </div>
                <div id="subnetList" class="d-flex flex-column gap-2">
                    <p class="text-muted small text-center"><i class="fas fa-spinner fa-spin me-1"></i>Loading…</p>
                </div>
            </div>

            <!-- ─ UTILIZATION TAB ─ -->
            <div class="tab-pane fade p-3" id="tabUtil">
                <div class="small fw-semibold mb-2">Subnet Utilization</div>
                <div id="utilPanel">
                    <p class="text-muted small text-center">—</p>
                </div>
            </div>

            <!-- ─ NAT/VIP TAB ─ -->
            <div class="tab-pane fade p-3" id="tabNat">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="small fw-semibold">
                        <i class="fas fa-random me-1"></i>NAT / VIP Mappings
                    </div>
                    <?php if ($is_admin): ?>
                    <button class="btn btn-sm btn-primary" onclick="openNatModal(0)">
                        <i class="fas fa-plus me-1"></i>Add Mapping
                    </button>
                    <?php endif; ?>
                </div>
                <div class="small text-muted mb-3" style="line-height:1.5">
                    Map firewall Virtual IPs / NAT addresses to their real internal hosts.
                    This helps correlate NAC MAC/IP data when a firewall owns a public or
                    virtual IP that is forwarded to a real server.
                </div>
                <div id="natTableWrap">
                    <p class="text-muted small text-center">Loading…</p>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN TABLE -->
    <div class="col-xl-9 col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header ch-dark d-flex flex-wrap align-items-center gap-2 justify-content-between">
                <h6 class="mb-0"><i class="fas fa-table me-2"></i>IP Inventory</h6>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <input id="searchBox" type="text" class="form-control form-control-sm"
                           style="width:155px" placeholder="IP / MAC / host…">
                    <select id="statusFilter" class="form-select form-select-sm" style="width:100px">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="reserved">Reserved</option>
                    </select>
                    <select id="subnetFilter" class="form-select form-select-sm" style="width:130px">
                        <option value="">All Subnets</option>
                    </select>
                    <button class="btn btn-sm btn-outline-warning" id="btnShowAlerts" title="Alerts only">
                        <i class="fas fa-bell"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnRefresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:22px"><input type="checkbox" id="selAll"></th>
                                <th style="width:120px">IP <i class="fas fa-sort fa-xs text-muted" data-sort="ip"></i></th>
                                <th style="width:148px">MAC</th>
                                <th>Hostname / Owner</th>
                                <th style="width:88px">Status</th>
                                <th style="width:62px">Ping</th>
                                <th style="width:65px">VLAN</th>
                                <th style="width:90px">First Seen</th>
                                <th style="width:105px">Last Seen</th>
                                <th>Notes</th>
                                <th style="width:128px">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ipamTbody">
                            <tr><td colspan="11" class="text-center py-4 text-muted">
                                <i class="fas fa-spinner fa-spin me-2"></i>Loading…
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center small py-2">
                <span id="tblInfo" class="text-muted">—</span>
                <span id="alertBanner"></span>
            </div>
        </div>
    </div>
</div><!-- /row -->
</div><!-- /container -->
</div><!-- /main-content -->

<!-- ══ MODALS (IP, Subnet, Ack, BulkAck, History) – keep the same as in the original file ══ -->

<!-- ══ IP EDIT MODAL ══ -->
<div class="modal fade" id="ipModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="ipForm" class="modal-content" novalidate>
            <div class="modal-header ch-dark">
                <h5 class="modal-title" id="ipModalTitle"><i class="fas fa-edit me-2"></i>Edit IP Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="fId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">IP Address <span class="text-danger">*</span></label>
                        <input name="ip" id="fIp" class="form-control" required placeholder="10.11.1.100">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">MAC Address</label>
                        <input name="mac" id="fMac" class="form-control font-monospace" placeholder="AA:BB:CC:DD:EE:FF">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Hostname / Owner</label>
                        <input name="assigned_to" id="fAssigned" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" id="fStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="reserved">Reserved</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">VLAN</label>
                        <input name="vlan" id="fVlan" class="form-control" placeholder="10">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ SUBNET EDIT MODAL ══ -->
<div class="modal fade" id="subnetModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="subnetForm" class="modal-content" novalidate>
            <div class="modal-header ch-dark">
                <h5 class="modal-title" id="snModalTitle"><i class="fas fa-plus me-2"></i>Add Subnet</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="snId">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">CIDR <span class="text-danger">*</span></label>
                        <input name="cidr" id="snCidr" class="form-control font-monospace" required
                               placeholder="192.168.1.0/24">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">VLAN ID</label>
                        <input name="vlan_id" id="snVlan" class="form-control" placeholder="10">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Label / Name</label>
                        <input name="label" id="snLabel" class="form-control" placeholder="Office LAN">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Interface Override</label>
                        <input name="interface" id="snIface" class="form-control" placeholder="Auto-detect">
                        <div class="form-text">Leave blank to auto-detect via routing table</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Scan Enabled</label>
                        <select name="scan_enabled" id="snScan" class="form-select">
                            <option value="1">Yes – include in auto-scan</option>
                            <option value="0">No – manual only</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="snDesc" class="form-control" rows="2"
                                  placeholder="e.g. Server VLAN, 3rd floor…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Subnet</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ ACKNOWLEDGE MODAL ══ -->
<div class="modal fade" id="ackModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h6 class="modal-title"><i class="fas fa-check-circle me-1"></i>Acknowledge Alert</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2" id="ackDetails"></p>
                <textarea id="ackNote" class="form-control form-control-sm" rows="3"
                          placeholder="Reason / action taken…"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning btn-sm" id="btnConfirmAck">
                    <i class="fas fa-check me-1"></i>Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ BULK ACK MODAL ══ -->
<div class="modal fade" id="bulkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h6 class="modal-title"><i class="fas fa-check-double me-1"></i>Clear All Alerts</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    Acknowledges <strong id="bulkCount">all</strong> unacknowledged alerts across every IP.
                </div>
                <select id="bulkReason" class="form-select form-select-sm mb-2">
                    <option value="False positive – wrong subnet scanned previously">False positive – wrong subnet</option>
                    <option value="Scheduled maintenance">Scheduled maintenance</option>
                    <option value="Known network change – reviewed">Known network change</option>
                    <option value="Reviewed and verified">Reviewed and verified</option>
                    <option value="custom">Custom note…</option>
                </select>
                <textarea id="bulkNote" class="form-control form-control-sm d-none" rows="2"
                          placeholder="Custom reason…"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning btn-sm" id="btnConfirmBulk">
                    <i class="fas fa-check-double me-1"></i>Acknowledge All
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ IP HISTORY DRILLDOWN MODAL ══ -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header ch-dark">
                <div>
                    <h5 class="modal-title mb-0" id="histTitle">
                        <i class="fas fa-history me-2 text-info"></i>IP History
                    </h5>
                    <div class="small text-muted mt-1" id="histSubtitle"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="histDevBar" class="d-flex flex-wrap gap-3 align-items-center p-3 border-bottom" style="background:rgba(59,130,246,.05)">
                    <span id="hd_ip"    class="badge bg-primary  font-monospace fs-6">—</span>
                    <span id="hd_mac"   class="badge bg-secondary font-monospace">—</span>
                    <span id="hd_host"  class="small fw-semibold text-muted">—</span>
                    <span id="hd_status"></span>
                    <span id="hd_vlan"  class="small text-muted"></span>
                    <span id="hd_first" class="small text-muted ms-auto"><i class="fas fa-calendar-plus me-1"></i>First seen: —</span>
                    <span id="hd_last"  class="small text-muted"><i class="fas fa-clock me-1"></i>Last seen: —</span>
                </div>
                <div class="p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0"><i class="fas fa-stream me-2 text-primary"></i>Change Timeline</h6>
                        <span id="histAlertCount" class="badge bg-danger"></span>
                    </div>
                    <div id="histTimeline" class="position-relative">
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-spinner fa-spin me-2"></i>Loading history…
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-sm btn-warning" id="histBulkAck">
                    <i class="fas fa-check-double me-1"></i>Ack All for this IP
                </button>
            </div>
        </div>
    </div>
</div>

<div id="toastStack"></div>
<script src="/js/bootstrap.bundle.min.js"></script>
<script>
/* ════════════════════════════════════════
   JANUS IPAM v3
════════════════════════════════════════ */
const ipModal     = new bootstrap.Modal('#ipModal');
const snModal     = new bootstrap.Modal('#subnetModal');
const ackModal    = new bootstrap.Modal('#ackModal');
const bulkModal   = new bootstrap.Modal('#bulkModal');

let allDevices    = [];
let allSubnets    = [];
let showAlerts    = false;
let pendingAckId  = null;
let cdTimer       = null;
let cdSecs        = 0, cdTotal = 0;
const CIRC        = 2 * Math.PI * 15.9;

/* ─── Toast ─── */
function toast(type, msg, dur=5500) {
    const icons={success:'fa-check-circle',danger:'fa-times-circle',
                 warning:'fa-exclamation-triangle',info:'fa-info-circle'};
    const el=document.createElement('div');
    el.className=`alert alert-${type} alert-dismissible shadow py-2 px-3 mb-0`;
    el.style.cssText='min-width:260px;max-width:420px;font-size:.875rem';
    el.innerHTML=`<i class="fas ${icons[type]||'fa-bell'} me-2"></i>${msg}
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>`;
    document.getElementById('toastStack').prepend(el);
    if (dur>0) setTimeout(()=>el.remove(),dur);
}

/* ─── Stats ─── */
function updateStats(data) {
    const today = new Date().toISOString().slice(0,10);
    const alerts = data.filter(d => d.alert_type && !d.acknowledged).length;

    // Exclude records that all share the same first_seen (table recreation artifact)
    const firstSeenTimes = data.map(d => d.first_seen).filter(Boolean).sort();
    const batchThreshold = firstSeenTimes.length > 0 ? firstSeenTimes[0] : null;
    const newToday = data.filter(d => d.first_seen && d.first_seen.startsWith(today) && d.first_seen !== batchThreshold).length;

    document.getElementById('sTotal').textContent    = data.length;
    document.getElementById('sActive').textContent   = data.filter(d => d.status === 'active').length;
    document.getElementById('sInactive').textContent = data.filter(d => d.status === 'inactive').length;
    document.getElementById('sReserved').textContent = data.filter(d => d.status === 'reserved').length;
    document.getElementById('sAlerts').textContent   = alerts;
    const sNewEl = document.getElementById('sNew'); if (sNewEl) sNewEl.textContent = newToday;

    document.getElementById('bulkCount').textContent = alerts;
    document.getElementById('alertBanner').innerHTML = alerts > 0
        ? `<span class="badge bg-danger"><i class="fas fa-bell me-1"></i>${alerts} unacked</span>`
        : `<span class="text-success small"><i class="fas fa-check-circle me-1"></i>All clear</span>`;

    buildUtil(data);
    buildSubnetFilter(data);
}

/* ─── Utilization ─── */
function buildUtil(data) {
    const g={};
    data.forEach(d=>{
        const p=d.ip.split('.').slice(0,3).join('.');
        if(!g[p]) g[p]={t:0,a:0};
        g[p].t++; if(d.status==='active') g[p].a++;
    });
    const panel=document.getElementById('utilPanel');
    const entries=Object.entries(g);
    if(!entries.length){panel.innerHTML='<p class="text-muted small text-center">No data</p>';return;}
    panel.innerHTML=entries.sort((a,b)=>b[1].t-a[1].t).map(([pfx,v])=>{
        const pct=Math.round((v.a/Math.max(v.t,1))*100);
        const col=pct>80?'#ef4444':pct>50?'#f59e0b':'#22c55e';
        return `<div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
                <span class="fw-semibold">${pfx}.0/24</span>
                <span class="text-muted">${v.a}/${v.t} (${pct}%)</span>
            </div>
            <div class="util-bar"><div class="util-fill" style="width:${pct}%;background:${col}"></div></div>
        </div>`;
    }).join('');
}

/* ─── Subnet filter dropdown ─── */
function buildSubnetFilter(data) {
    const seen=new Set();
    data.forEach(d=>seen.add(d.ip.split('.').slice(0,3).join('.')+'.0/24'));
    const sel=document.getElementById('subnetFilter');
    const cur=sel.value;
    sel.innerHTML='<option value="">All Subnets</option>';
    [...seen].sort().forEach(s=>{
        const o=document.createElement('option');
        o.value=s; o.textContent=s; sel.appendChild(o);
    });
    if (cur) sel.value=cur;
}

/* ─── Helpers ─── */
const fmtD=ds=>{
    if(!ds||ds==='0000-00-00 00:00:00') return '—';
    return new Date(ds).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
};
const ago=ds=>{
    if(!ds) return '';
    const ms=Date.now()-new Date(ds).getTime();
    const m=Math.floor(ms/60000),h=Math.floor(ms/3600000),d=Math.floor(ms/86400000);
    return m<1?'just now':m<60?`${m}m ago`:h<24?`${h}h ago`:`${d}d ago`;
};
const rowCls=d=>{
    if(d.alert_type==='spoofing'&&!d.acknowledged) return 'row-spoofing row-unacked';
    if(d.alert_type&&!d.acknowledged) return 'row-changed row-unacked';
    if(d.status==='active')   return 'row-active';
    if(d.status==='inactive') return 'row-inactive';
    if(d.status==='reserved') return 'row-reserved';
    return '';
};
const sBadge=s=>({active:'success',inactive:'secondary',reserved:'warning'})[s]||'secondary';

/* ─── Render table ─── */
function renderTable(data) {
    const search=document.getElementById('searchBox').value.toLowerCase();
    const sfilt =document.getElementById('statusFilter').value;
    const snfilt=document.getElementById('subnetFilter').value;

    const vis=data.filter(d=>{
        if(sfilt&&d.status!==sfilt) return false;
        if(showAlerts&&(!d.alert_type||d.acknowledged)) return false;
        if(snfilt){
            const pfx=d.ip.split('.').slice(0,3).join('.')+'.0/24';
            if(pfx!==snfilt) return false;
        }
        if(search) return [d.ip,d.mac,d.assigned_to,d.notes,d.vlan]
            .some(v=>(v||'').toLowerCase().includes(search));
        return true;
    });

    document.getElementById('tblInfo').textContent=`Showing ${vis.length} of ${data.length} records`;
    const tbody=document.getElementById('ipamTbody');

    if(!vis.length){
        tbody.innerHTML=`<tr><td colspan="11" class="text-center py-4 text-muted">
            <i class="fas fa-inbox me-2"></i>No records match.</td></tr>`;
        return;
    }

    // Store device data for edit lookups
    window._ipamDevMap = {};
    vis.forEach(d => { window._ipamDevMap[d.id] = d; });

    tbody.innerHTML=vis.map(d=>{
        const isAlert=d.alert_type&&!d.acknowledged;
        const alertIco=d.alert_type==='spoofing'
            ?`<i class="fas fa-skull-crossbones text-danger ms-1" title="MAC Spoofing!"></i>`
            :d.alert_type?`<i class="fas fa-exclamation-circle text-warning ms-1"></i>`:'';
        const ackBadge=d.acknowledged
            ?`<span class="badge bg-success ms-1" style="font-size:.6rem"
                title="Acked by ${d.ack_by||'?'}${d.ack_note?' – '+d.ack_note:''}">✓</span>`:'';
        const isClickable = (d.alert_type && !d.acknowledged) || d.alert_type;
        const trClick = isClickable ? `onclick="openHistory('${d.ip}',${d.id})" title="Click to view change history"` : '';
        return `<tr class="${rowCls(d)}" ${trClick}>
            <td><input type="checkbox" class="row-check" value="${d.id}"></td>
            <td class="ip-cell">${d.ip}${alertIco}${isClickable?'<br><span class=\"drill-hint\">↗ view history</span>':''}</td>
            <td class="mac-cell">${d.mac||'<span class="text-muted fst-italic">no MAC</span>'}</td>
            <td>${d.assigned_to||'<span class="text-muted">—</span>'}${ackBadge}</td>
            <td><span class="badge bg-${sBadge(d.status)}">${d.status}</span></td>
            <td onclick="event.stopPropagation()"><span class="ping-btn badge bg-secondary" id="pb_${d.id}"
                      onclick="doPing('${d.ip}',${d.id})" title="Click to ping">?</span></td>
            <td class="small text-muted">${d.vlan||'—'}</td>
            <td class="small text-muted">${fmtD(d.first_seen)}</td>
            <td class="small">
                <div>${fmtD(d.last_seen)}</div>
                <div class="text-muted" style="font-size:.72rem">${ago(d.last_seen)}</div>
            </td>
            <td class="note-cell small text-muted" title="${d.notes||''}">${d.notes||'—'}</td>
            <td onclick="event.stopPropagation()"><div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary py-0 px-1"
                    onclick="openEditIP(window._ipamDevMap[${d.id}])" title="Edit"><i class="fas fa-edit fa-xs"></i></button>
                <button class="btn btn-outline-danger py-0 px-1"
                    onclick="delIP(${d.id})" title="Delete"><i class="fas fa-trash fa-xs"></i></button>
                ${isAlert?`<button class="btn btn-warning py-0 px-1"
                    onclick="openAck(${d.id},'${d.ip}','${d.alert_type}')"
                    title="Acknowledge"><i class="fas fa-check fa-xs"></i></button>`:''}
                <button class="btn btn-outline-info py-0 px-1"
                    onclick="openHistory('${d.ip}',${d.id})" title="View history"><i class="fas fa-history fa-xs"></i></button>
            </div></td>
        </tr>`;
    }).join('');
}

/* ─── Load data ─── */
function loadData() {
    const fd=new FormData(); fd.append('action','load');
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(data=>{
            if(data.error){toast('danger',data.error);return;}
            allDevices=data; renderTable(data); updateStats(data);
        }).catch(e=>toast('danger','Load failed: '+e.message));
}

/* ─── Load subnets ─── */
function loadSubnets() {
    const fd=new FormData(); fd.append('action','subnets_load');
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(data=>{
            if(data.error){toast('danger','Subnet load failed: '+data.error);return;}
            allSubnets=data;
            renderSubnetPills(data);
            renderSubnetList(data);
        }).catch(e=>toast('danger','Subnet load failed: '+e.message));
}

/* ─── Subnet pills (scanner tab) ─── */
function renderSubnetPills(subnets) {
    const el=document.getElementById('subnetPills');
    if(!subnets.length){
        el.innerHTML='<p class="text-muted small text-center">No subnets. Add one in the Subnets tab.</p>';
        return;
    }
    el.innerHTML=subnets.map((s,i)=>{
        const tc = s.device_count||0, ac = s.active_count||0;
        const pct = tc>0 ? Math.round((ac/tc)*100) : 0;
        const barCol = pct>80?'#ef4444':pct>50?'#f59e0b':'#22c55e';
        return `<div class="sn-pill px-2 py-2 small ${i===0?'active-sn':''}" data-cidr="${s.cidr}">
            <div class="d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-network-wired me-1 text-primary"></i>
                    <strong>${s.cidr}</strong>
                    <span class="sn-iface-badge badge bg-secondary ms-1">${s.interface_auto||'auto'}</span>
                    ${!s.scan_enabled?'<span class="badge bg-dark ms-1" style="font-size:.6rem">manual</span>':''}
                </span>
                <span class="text-muted" style="font-size:.7rem">${ac}/${tc}</span>
            </div>
            <div class="sn-bar mt-1">
                <div class="sn-bar-fill" style="width:${pct}%;background:${barCol}"></div>
            </div>
            ${s.label?`<div style="font-size:.68rem;color:#9ca3af;margin-top:2px">${s.label}</div>`:''}
        </div>`;
    }).join('');
    if(subnets.length) document.getElementById('scanCIDR').value=subnets[0].cidr;

    el.querySelectorAll('.sn-pill').forEach(p=>{
        p.addEventListener('click',()=>{
            el.querySelectorAll('.sn-pill').forEach(x=>x.classList.remove('active-sn'));
            p.classList.add('active-sn');
            document.getElementById('scanCIDR').value=p.dataset.cidr;
        });
    });
}

/* ─── Subnet list (subnets tab) ─── */
function renderSubnetList(subnets) {
    window._ipamSnMap = {};
    subnets.forEach(s => { window._ipamSnMap[s.id] = s; });
    const el=document.getElementById('subnetList');
    if(!subnets.length){
        el.innerHTML='<p class="text-muted small text-center">No subnets registered yet.</p>';
        return;
    }
    el.innerHTML=subnets.map(s=>`
        <div class="subnet-row rounded p-2 small">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong class="font-monospace">${s.cidr}</strong>
                    ${s.label?`<span class="text-muted ms-1">– ${s.label}</span>`:''}
                    ${s.vlan_id?`<span class="badge bg-info ms-1 subnet-scan-badge">VLAN ${s.vlan_id}</span>`:''}
                </div>
                <div class="d-flex gap-1">
                    <button class="btn btn-xs btn-outline-success py-0 px-1" style="font-size:.7rem"
                        onclick="quickScan('${s.cidr}')" title="Scan this subnet">
                        <i class="fas fa-play fa-xs"></i>
                    </button>
                    <button class="btn btn-xs btn-outline-primary py-0 px-1" style="font-size:.7rem"
                        onclick="openEditSubnet(window._ipamSnMap[${s.id}])" title="Edit">
                        <i class="fas fa-edit fa-xs"></i>
                    </button>
                    <button class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.7rem"
                        onclick="delSubnet(${s.id})" title="Delete">
                        <i class="fas fa-trash fa-xs"></i>
                    </button>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
                <span class="badge bg-dark" style="font-size:.68rem"><i class="fas fa-desktop me-1"></i>${s.device_count} total</span>
                <span class="badge bg-success" style="font-size:.68rem"><i class="fas fa-circle me-1"></i>${s.active_count} online</span>
                <span class="badge bg-secondary" style="font-size:.68rem">${s.device_count-s.active_count} offline</span>
                <span class="badge bg-info" style="font-size:.68rem"><i class="fas fa-route me-1"></i>${s.interface_auto||'auto'}</span>
                <span class="badge ${s.scan_enabled?'bg-primary':'bg-dark'} subnet-scan-badge"
                      style="font-size:.68rem">
                    ${s.scan_enabled?'<i class="fas fa-sync-alt me-1"></i>auto-scan':'manual'}
                </span>
                ${s.vlan_id?`<span class="badge bg-warning text-dark" style="font-size:.68rem">VLAN ${s.vlan_id}</span>`:''}
            </div>
            <div class="mt-1" style="height:4px;background:rgba(0,0,0,.1);border-radius:2px;overflow:hidden">
                <div style="height:100%;width:${s.device_count>0?Math.round((s.active_count/s.device_count)*100):0}%;background:#22c55e;transition:width .5s"></div>
            </div>
            ${s.description?`<div class="text-muted mt-1" style="font-size:.75rem">${s.description}</div>`:''}
        </div>
    `).join('');
}

/* ─── Ping ─── */
function doPing(ip,id) {
    const b=document.getElementById('pb_'+id);
    if(!b) return;
    b.textContent='…'; b.className='ping-btn badge bg-secondary';
    const fd=new FormData(); fd.append('action','ping'); fd.append('ip',ip);
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.alive){b.textContent=d.rtt?d.rtt+'ms':'UP';b.className='ping-btn badge bg-success';}
        else{b.textContent='DOWN';b.className='ping-btn badge bg-danger';}
    }).catch(()=>{b.textContent='ERR';b.className='ping-btn badge bg-warning text-dark';});
}

/* ─── Scan ─── */
function runScan(cidr) {
    const bar=document.getElementById('scanBar'),prg=document.getElementById('scanPrg');
    const btn=document.getElementById('btnScan'),txt=document.getElementById('scanTxt');
    bar.classList.remove('d-none'); prg.style.width='5%';
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin me-1"></i>Scanning…';
    txt.textContent='Scanning '+cidr+'…';
    let pct=5;
    const tick=setInterval(()=>{pct=Math.min(pct+2.5,88);prg.style.width=pct+'%';},400);
    const t0=Date.now();
    const fd=new FormData(); fd.append('network',cidr);
    return fetch('/modules/ipam/ipam_scan.php',{method:'POST',body:fd})
        .then(r=>{
            if(!r.ok) throw new Error('HTTP '+r.status);
            return r.text();
        })
        .then(raw=>{
            let d;
            try { d=JSON.parse(raw); }
            catch(e) { throw new Error('Invalid JSON from scan – check PHP errors: '+raw.slice(0,200)); }
            clearInterval(tick); prg.style.width='100%';
            const dur=((Date.now()-t0)/1000).toFixed(1);
            document.getElementById('infoTime').textContent=new Date().toLocaleTimeString();
            document.getElementById('infoDur').textContent=dur+'s';
            document.getElementById('infoIface').textContent=d.iface||'—';
            document.getElementById('infoFound').textContent=d.scanned??'?';
            document.getElementById('infoChanges').textContent=(d.changes||[]).length;
            txt.textContent=`Done – ${d.scanned||0} devices in ${dur}s via ${d.iface||'?'}`;
            if(d.error){toast('danger','Scan error: '+d.error);}
            else{
                const chg=d.changes||[];
                toast('success',`${d.cidr}: ${d.scanned} device(s) in ${dur}s`+(chg.length?` | ${chg.length} change(s)`:''));
                chg.forEach(c=>{
                    if(c.type==='spoofing') toast('danger',`<strong>⚠ MAC SPOOFING:</strong> ${c.note}`,15000);
                    else if(c.type==='mac_change') toast('warning',`MAC change ${c.ip}: ${c.old_mac} → ${c.mac}`);
                    else if(c.type==='new') toast('info',`New device: ${c.ip}${c.mac?' ('+c.mac+')':''}`,4000);
                });
                loadData(); loadSubnets();
            }
            setTimeout(()=>{bar.classList.add('d-none'); txt.textContent='';},3000);
            return d;
        })
        .catch(e=>{
            clearInterval(tick); bar.classList.add('d-none');
            toast('danger','Scan failed: '+e.message);
            txt.textContent='Scan failed – '+e.message.slice(0,60);
        })
        .finally(()=>{
            btn.disabled=false;
            btn.innerHTML='<i class="fas fa-search-location me-1"></i>Scan Now';
        });
}

document.getElementById('btnScan').addEventListener('click',()=>{
    const c=document.getElementById('scanCIDR').value.trim();
    if(!c){toast('warning','Enter a CIDR range');return;}
    runScan(c);
});
function quickScan(cidr){
    document.getElementById('scanCIDR').value=cidr;
    bootstrap.Tab.getOrCreateInstance(document.querySelector('[href="#tabScanner"]')).show();
    runScan(cidr);
}
document.getElementById('btnScanAll').addEventListener('click', async function(){
    const enabled=allSubnets.filter(s=>s.scan_enabled);
    if(!enabled.length){toast('warning','No subnets with auto-scan enabled');return;}
    this.disabled=true; this.innerHTML='<i class="fas fa-spinner fa-spin me-1"></i>Scanning…';
    for(const s of enabled){
        document.getElementById('scanCIDR').value=s.cidr;
        toast('info',`Starting scan: ${s.cidr}`,2000);
        await runScan(s.cidr);
        await new Promise(r=>setTimeout(r,500));
    }
    this.disabled=false; this.innerHTML='<i class="fas fa-globe me-1"></i>Scan All Enabled';
    toast('success','All subnets scanned');
});

/* ─── Auto-scan countdown ─── */
document.getElementById('autoToggle').addEventListener('change',function(){
    if(this.checked) startCD(); else stopCD();
});
document.getElementById('autoInterval').addEventListener('change',()=>{
    if(document.getElementById('autoToggle').checked){stopCD();startCD();}
});
function startCD(){
    cdTotal=cdSecs=parseInt(document.getElementById('autoInterval').value);
    document.getElementById('autoScanBadge').textContent='Auto';
    document.getElementById('autoScanBadge').className='badge bg-success';
    document.getElementById('cdArea').classList.remove('d-none');
    drawCD();
    cdTimer=setInterval(()=>{
        cdSecs--;
        if(cdSecs<=0){ runScan(document.getElementById('scanCIDR').value.trim()); cdSecs=cdTotal; }
        drawCD();
    },1000);
    toast('info',`Auto-scan every ${cdTotal/60}min enabled`);
}
function stopCD(){
    clearInterval(cdTimer);
    document.getElementById('autoScanBadge').textContent='Manual';
    document.getElementById('autoScanBadge').className='badge bg-secondary';
    document.getElementById('cdArea').classList.add('d-none');
}
function drawCD(){
    const ring=document.getElementById('cdRing'),txt=document.getElementById('cdTxt');
    const pct=cdSecs/cdTotal;
    ring.style.strokeDasharray=CIRC; ring.style.strokeDashoffset=CIRC*(1-pct);
    ring.style.stroke=pct>.5?'#22c55e':pct>.2?'#f59e0b':'#ef4444';
    const m=Math.floor(cdSecs/60),s=cdSecs%60;
    txt.textContent=m>0?`${m}m ${s}s`:`${s}s`;
}

/* ─── Filters ─── */
['searchBox','statusFilter','subnetFilter'].forEach(id=>{
    document.getElementById(id).addEventListener(id==='searchBox'?'input':'change',()=>renderTable(allDevices));
});
document.getElementById('btnRefresh').addEventListener('click',()=>{loadData();loadSubnets();});
document.getElementById('btnShowAlerts').addEventListener('click',function(){
    showAlerts=!showAlerts;
    this.classList.toggle('btn-warning',showAlerts);
    this.classList.toggle('btn-outline-warning',!showAlerts);
    renderTable(allDevices);
});
document.getElementById('selAll').addEventListener('change',function(){
    document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked);
});

/* ─── IP Edit ─── */
function openEditIP(d={}) {
    document.getElementById('ipModalTitle').innerHTML=d.id
        ?'<i class="fas fa-edit me-2"></i>Edit IP Record'
        :'<i class="fas fa-plus me-2"></i>Add IP Record';
    document.getElementById('fId').value=d.id||'';
    document.getElementById('fIp').value=d.ip||'';
    document.getElementById('fMac').value=d.mac||'';
    document.getElementById('fAssigned').value=d.assigned_to||'';
    document.getElementById('fStatus').value=d.status||'active';
    document.getElementById('fVlan').value=d.vlan||'';
    document.getElementById('fNotes').value=d.notes||'';
    ipModal.show();
}
document.getElementById('btnAddIP')?.addEventListener('click',()=>openEditIP());
document.getElementById('ipForm').addEventListener('submit',function(e){
    e.preventDefault();
    const fd=new FormData(this); fd.append('action','save');
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{
        if(t.startsWith('error')){toast('danger','Save: '+t);return;}
        ipModal.hide(); toast('success','IP record saved'); loadData();
    }).catch(e=>toast('danger',e.message));
});

function delIP(id){
    if(!confirm('Delete this IP record?')) return;
    const fd=new FormData(); fd.append('action','delete'); fd.append('id',id);
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{
        if(t==='ok'){toast('success','Deleted');loadData();}
        else toast('danger','Delete: '+t);
    });
}

/* ─── Subnet Edit ─── */
function openEditSubnet(s={}) {
    document.getElementById('snModalTitle').innerHTML=s.id
        ?'<i class="fas fa-edit me-2"></i>Edit Subnet'
        :'<i class="fas fa-plus me-2"></i>Add Subnet';
    document.getElementById('snId').value=s.id||'';
    document.getElementById('snCidr').value=s.cidr||'';
    document.getElementById('snLabel').value=s.label||'';
    document.getElementById('snVlan').value=s.vlan_id||'';
    document.getElementById('snDesc').value=s.description||'';
    document.getElementById('snIface').value=s.interface||'';
    document.getElementById('snScan').value=s.scan_enabled??1;
    snModal.show();
}
document.getElementById('btnAddSubnet').addEventListener('click',()=>openEditSubnet());
document.getElementById('subnetForm').addEventListener('submit',function(e){
    e.preventDefault();
    const fd=new FormData(this); fd.append('action','subnet_save');
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{
        if(t.startsWith('error')){toast('danger','Subnet save: '+t);return;}
        snModal.hide(); toast('success','Subnet saved'); loadSubnets();
    }).catch(e=>toast('danger',e.message));
});
function delSubnet(id){
    if(!confirm('Delete this subnet record? IP entries in this subnet are NOT deleted.')) return;
    const fd=new FormData(); fd.append('action','subnet_delete'); fd.append('id',id);
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{
        if(t==='ok'){toast('success','Subnet deleted');loadSubnets();}
        else toast('danger','Delete: '+t);
    });
}

/* ─── Acknowledge ─── */
function openAck(id,ip,type){
    pendingAckId=id;
    document.getElementById('ackDetails').innerHTML=`IP <strong>${ip}</strong> — <span class="badge bg-warning text-dark">${type}</span>`;
    document.getElementById('ackNote').value='';
    ackModal.show();
}
document.getElementById('btnConfirmAck').addEventListener('click',()=>{
    if(!pendingAckId) return;
    const fd=new FormData();
    fd.append('action','acknowledge'); fd.append('id',pendingAckId);
    fd.append('note',document.getElementById('ackNote').value.trim());
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{
        ackModal.hide();
        if(t==='ok'){toast('success','Acknowledged');loadData();}
        else toast('danger','Ack failed: '+t);
    });
});

/* ─── Bulk Ack ─── */
document.getElementById('btnBulkAck').addEventListener('click',()=>bulkModal.show());
document.getElementById('bulkReason').addEventListener('change',function(){
    document.getElementById('bulkNote').classList.toggle('d-none',this.value!=='custom');
});
document.getElementById('btnConfirmBulk').addEventListener('click',()=>{
    const sel=document.getElementById('bulkReason');
    const note=sel.value==='custom'?document.getElementById('bulkNote').value.trim():sel.value;
    const fd=new FormData(); fd.append('action','bulk_ack'); fd.append('note',note);
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{
        bulkModal.hide();
        if(t.startsWith('ok')){toast('success',t.replace('ok:','').trim());loadData();}
        else toast('danger','Bulk ack: '+t);
    });
});

/* ─── Export ─── */
document.getElementById('btnExport').addEventListener('click',()=>{
    if(!allDevices.length){toast('warning','No data');return;}
    const cols=['ip','mac','assigned_to','status','vlan','notes','first_seen','last_seen'];
    const csv=[cols.join(','),...allDevices.map(d=>
        cols.map(k=>`"${(d[k]||'').toString().replace(/"/g,'""')}"`).join(',')
    )].join('\n');
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download=`ipam_${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); toast('success','CSV exported');
});

/* ─── IP History Drilldown ─── */
const historyModal = new bootstrap.Modal('#historyModal');
let histCurrentIP = null;

function openHistory(ip, id) {
    histCurrentIP = ip;
    const dev = window._ipamDevMap ? window._ipamDevMap[id] : null;
    document.getElementById('histTitle').innerHTML =
        `<i class="fas fa-history me-2 text-info"></i>History: <span class="font-monospace text-warning">${ip}</span>`;
    document.getElementById('histSubtitle').textContent =
        dev ? (dev.assigned_to || 'Unknown device') + (dev.notes ? ' — ' + dev.notes.slice(0,60) : '') : '';

    if (dev) {
        document.getElementById('hd_ip').textContent    = dev.ip;
        document.getElementById('hd_mac').textContent   = dev.mac || 'no MAC';
        document.getElementById('hd_host').textContent  = dev.assigned_to || '—';
        document.getElementById('hd_status').innerHTML  =
            `<span class="badge bg-${{active:'success',inactive:'secondary',reserved:'warning'}[dev.status]||'secondary'}">${dev.status}</span>`;
        document.getElementById('hd_vlan').textContent  = dev.vlan ? 'VLAN: '+dev.vlan : '';
        document.getElementById('hd_first').innerHTML   = `<i class="fas fa-calendar-plus me-1"></i>First: ${fmtD(dev.first_seen)}`;
        document.getElementById('hd_last').innerHTML    = `<i class="fas fa-clock me-1"></i>Last: ${fmtD(dev.last_seen)}`;
    }

    document.getElementById('histTimeline').innerHTML =
        '<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</div>';
    document.getElementById('histAlertCount').textContent = '';

    historyModal.show();
    loadHistory(ip);
}

function loadHistory(ip) {
    const fd = new FormData();
    fd.append('action', 'ip_history');
    fd.append('ip', ip);
    fetch('/modules/ipam/ipam_ajax.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('histTimeline').innerHTML =
                    `<div class="alert alert-danger">${data.error}</div>`;
                return;
            }
            renderTimeline(data);
        })
        .catch(e => {
            document.getElementById('histTimeline').innerHTML =
                `<div class="alert alert-danger">Failed: ${e.message}</div>`;
        });
}

function renderTimeline(logs) {
    const el = document.getElementById('histTimeline');
    const unacked = logs.filter(l => !l.acknowledged).length;
    document.getElementById('histAlertCount').textContent =
        unacked > 0 ? `${unacked} unacknowledged` : '';

    if (!logs.length) {
        el.innerHTML = `<div class="text-center py-4 text-muted">
            <i class="fas fa-check-circle text-success fa-2x mb-2"></i><br>
            No change events recorded for this IP.
        </div>`;
        return;
    }

    const typeMap = {
        spoof:   {cls:'tl-spoof',  icon:'fa-skull-crossbones', label:'MAC Spoofing'},
        MAC:     {cls:'tl-mac',    icon:'fa-exchange-alt',     label:'MAC Change'},
        offline: {cls:'tl-offline',icon:'fa-times-circle',     label:'Went Offline'},
        online:  {cls:'tl-online', icon:'fa-check-circle',     label:'Came Online'},
        New:     {cls:'tl-new',    icon:'fa-plus-circle',      label:'Discovered'},
    };

    const getType = (note) => {
        if (!note) return typeMap[Object.keys(typeMap)[0]];
        for (const [k,v] of Object.entries(typeMap)) {
            if (note.includes(k)) return v;
        }
        return {cls:'tl-info', icon:'fa-info-circle', label:'Info'};
    };

    el.innerHTML = logs.map(log => {
        const t = getType(log.note);
        const acked = log.acknowledged == 1;
        const dt = new Date(log.changed_at);
        const timeStr = dt.toLocaleString('en-GB', {
            day:'2-digit',month:'short',year:'numeric',
            hour:'2-digit',minute:'2-digit'
        });
        const macChange = (log.old_mac && log.new_mac && log.old_mac !== log.new_mac)
            ? `<div class="mt-1 font-monospace" style="font-size:.78rem">
                <span class="text-muted">${log.old_mac||'—'}</span>
                <i class="fas fa-arrow-right mx-1 text-warning"></i>
                <span class="text-light">${log.new_mac||'—'}</span>
               </div>` : '';
        const ackInfo = acked
            ? `<div class="mt-1 text-success" style="font-size:.72rem">
                <i class="fas fa-check-circle me-1"></i>Acked by
                <strong>${log.acknowledged_by||'?'}</strong>
                ${log.ack_note ? '— ' + log.ack_note.slice(0,50) : ''}
               </div>` : '';
        const ackBtn = !acked
            ? `<button class="btn btn-warning btn-sm py-0 px-2 mt-1 tl-ack-btn"
                style="font-size:.72rem"
                data-log-id="${log.id}" data-ip="${log.ip}">
                <i class="fas fa-check me-1"></i>Ack
               </button>` : '';

        return `<div class="timeline-item ${acked?'tl-acked':''}">
            <div class="timeline-dot ${t.cls}"><i class="fas ${t.icon}"></i></div>
            <div class="d-flex justify-content-between align-items-start">
                <span class="badge bg-${acked?'secondary':t.cls.replace('tl-','').replace('spoof','danger').replace('mac','warning').replace('offline','secondary').replace('online','success').replace('new','primary').replace('info','info')} mb-1">
                    <i class="fas ${t.icon} me-1"></i>${t.label}
                </span>
                <span class="timeline-time">${timeStr}</span>
            </div>
            <div class="timeline-note text-muted">${log.note||''}</div>
            ${macChange}${ackInfo}${ackBtn}
        </div>`;
    }).join('');

    el.querySelectorAll('.tl-ack-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const logId = btn.dataset.logId;
            const fd = new FormData();
            fd.append('action','ack_log');
            fd.append('log_id', logId);
            fd.append('note', 'Acknowledged from IP history panel');
            fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd})
                .then(r=>r.text()).then(t=>{
                    if(t.trim()==='ok') {
                        toast('success','Acknowledged');
                        loadHistory(histCurrentIP);
                        loadData();
                    } else toast('danger','Ack failed: '+t);
                });
        });
    });
}

document.getElementById('histBulkAck').addEventListener('click', () => {
    if (!histCurrentIP) return;
    const fd = new FormData();
    fd.append('action','bulk_ack_ip');
    fd.append('ip', histCurrentIP);
    fd.append('note','Bulk ack from history panel');
    fetch('/modules/ipam/ipam_ajax.php',{method:'POST',body:fd})
        .then(r=>r.text()).then(t=>{
            if(t.startsWith('ok')) {
                toast('success',t.replace('ok:','').trim());
                loadHistory(histCurrentIP);
                loadData();
            } else toast('danger','Bulk ack failed: '+t);
        });
});

/* ─── Init ─── */
document.addEventListener('DOMContentLoaded',()=>{
    loadData();
    loadSubnets();
    const sb=document.getElementById('sidebar'),mc=document.getElementById('main-content');
    const tb=document.querySelector('.topbar');
    const syncLayout = () => {
        const w = sb.classList.contains('collapsed') ? '64px' : '260px';
        if(mc) { mc.style.marginLeft=w; mc.style.transition='margin-left 0.3s ease'; }
        if(tb) { tb.style.left=w; tb.style.transition='left 0.3s ease'; }
    };
    if(sb) {
        new MutationObserver(syncLayout).observe(sb,{attributes:true,attributeFilter:['class']});
        syncLayout();
    }
});

/* ─── NAT/VIP ─── */
async function loadNatMap() {
    const wrap = document.getElementById('natTableWrap');
    if (!wrap) return;
    wrap.innerHTML = '<p class="text-muted small text-center">Loading…</p>';
    const rows = await fetch('/noc/nac_ajax.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=nat_list'
    }).then(r=>r.json()).catch(()=>[]);

    if (!rows.length) {
        wrap.innerHTML = '<p class="text-muted small text-center">No NAT/VIP mappings yet. Click <strong>Add Mapping</strong> to create one.</p>';
        return;
    }
    const typeColors = { dnat:'var(--bs-info)', vip:'var(--bs-warning)', snat:'var(--bs-secondary)' };
    wrap.innerHTML = `<table class="table table-hover table-sm mb-0 small">
      <thead class="table-light"><tr>
        <th>Virtual / Public IP</th><th>Real Internal IP</th><th>Type</th>
        <th>Description</th><th>Updated</th><th></th>
      </tr></thead>
      <tbody>
      ${rows.map(r=>`<tr>
        <td><code>${escHtml(r.vip)}</code></td>
        <td><code>${escHtml(r.real_ip)}</code></td>
        <td><span class="badge" style="background:${typeColors[r.nat_type]||'#666'}">${escHtml(r.nat_type.toUpperCase())}</span></td>
        <td>${escHtml(r.description||'—')}</td>
        <td class="text-muted">${r.updated_at ? r.updated_at.substring(0,16) : '—'}</td>
        <td>
          <button class="btn btn-xs btn-outline-secondary btn-sm py-0 px-1 me-1"
            onclick="openNatModal(${r.id})">✏️</button>
          <button class="btn btn-xs btn-outline-danger btn-sm py-0 px-1"
            onclick="deleteNat(${r.id})">🗑</button>
        </td>
      </tr>`).join('')}
      </tbody></table>`;
}

function openNatModal(id) {
    const existing = document.getElementById('natModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'natModal';
    modal.className = 'modal fade';
    modal.innerHTML = `
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-random me-2"></i>${id ? 'Edit' : 'Add'} NAT / VIP Mapping</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="natId" value="${id}">
            <div class="mb-3">
              <label class="form-label small fw-semibold">Virtual / Public IP <span class="text-danger">*</span></label>
              <input type="text" id="natVip" class="form-control form-control-sm" placeholder="e.g. 203.0.113.10">
              <div class="form-text">The IP address that external/firewall traffic arrives on</div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Real Internal IP <span class="text-danger">*</span></label>
              <input type="text" id="natReal" class="form-control form-control-sm" placeholder="e.g. 172.17.125.10">
              <div class="form-text">The actual host IP this forwards/maps to</div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Type</label>
              <select id="natType" class="form-select form-select-sm">
                <option value="dnat">DNAT (Destination NAT / Port Forward)</option>
                <option value="vip">VIP (Virtual IP / Load Balanced)</option>
                <option value="snat">SNAT (Source NAT / Masquerade)</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Description</label>
              <input type="text" id="natDesc" class="form-control form-control-sm" placeholder="e.g. Web server public access">
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary btn-sm" onclick="saveNat()">
              <i class="fas fa-save me-1"></i>Save
            </button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);

    if (id) {
        fetch('/noc/nac_ajax.php', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=nat_list'
        }).then(r=>r.json()).then(rows => {
            const row = rows.find(r=>r.id==id);
            if (row) {
                document.getElementById('natVip').value  = row.vip || '';
                document.getElementById('natReal').value = row.real_ip || '';
                document.getElementById('natType').value = row.nat_type || 'dnat';
                document.getElementById('natDesc').value = row.description || '';
            }
        });
    }

    new bootstrap.Modal(modal).show();
}

async function saveNat() {
    const id   = document.getElementById('natId').value;
    const vip  = document.getElementById('natVip').value.trim();
    const real = document.getElementById('natReal').value.trim();
    const type = document.getElementById('natType').value;
    const desc = document.getElementById('natDesc').value.trim();
    if (!vip || !real) { alert('VIP and Real IP are required'); return; }
    await fetch('/noc/nac_ajax.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=nat_save&id=${id}&vip=${encodeURIComponent(vip)}&real_ip=${encodeURIComponent(real)}&nat_type=${type}&description=${encodeURIComponent(desc)}`
    });
    bootstrap.Modal.getInstance(document.getElementById('natModal'))?.hide();
    loadNatMap();
}

async function deleteNat(id) {
    if (!confirm('Delete this NAT/VIP mapping?')) return;
    await fetch('/noc/nac_ajax.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=nat_delete&id=${id}`
    });
    loadNatMap();
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
