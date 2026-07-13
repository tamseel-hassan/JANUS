<?php
/**
 * noc/nac.php – NAC Dashboard
 * Network Access Control – Switch Fleet Overview
 */
require_once __DIR__ . '/../../auth_check.php';
$pageTitle = 'NAC – Network Access Control';
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> | Janus</title>
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/font-aweso/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css">
<link rel="stylesheet" href="/css/pages/nac.css">
</head>
<body>
<?php include __DIR__ . '/../../topbar.php'; include __DIR__ . '/../../sidebar.php'; ?>
<div id="main-content">

      <!-- Top action bar -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div>
          <h1 style="font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-network-wired" style="color:var(--nac-accent)"></i>
            Network Access Control
          </h1>
          <p style="font-size:.75rem;color:var(--nac-muted);margin-top:2px;">
            Switch fleet monitoring · Layer 2 MAC tracking · Port-level visibility
          </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <!-- Search bar -->
          <div class="nac-search-wrap">
            <i data-lucide="search" class="icon-lucide srch-icon"></i>
            <input type="text" id="globalSearch" placeholder="Search IP, MAC, hostname…" autocomplete="off">
            <div class="search-results" id="searchResults"></div>
          </div>
          <button class="btn btn-ghost btn-sm" onclick="pollAll()">
            <i data-lucide="refresh-ccw" class="icon-lucide -alt"></i> Poll All
          </button>
          <button class="btn btn-ghost btn-sm" onclick="openGroupManager()" style="border-color:rgba(88,166,255,.4)">
            <i class="fas fa-layer-group" style="color:var(--nac-accent)"></i> Groups
          </button>
          <button class="btn btn-primary btn-sm" onclick="openAddSwitch()">
            <i data-lucide="plus" class="icon-lucide"></i> Add Switch
          </button>
        </div>
      </div>

      <!-- Stat cards -->
      <div class="stat-grid" id="statGrid">
        <div class="stat-card"><div class="sc-label">Switches</div><div class="sc-value sc-blue" id="st-total">—</div><div class="sc-sub">configured</div></div>
        <div class="stat-card"><div class="sc-label">Online</div><div class="sc-value sc-green" id="st-ok">—</div><div class="sc-sub">polling OK</div></div>
        <div class="stat-card"><div class="sc-label">Errors</div><div class="sc-value sc-red" id="st-err">—</div><div class="sc-sub">poll failed</div></div>
        <div class="stat-card clickable" onclick="openAnalyticsAndShow('access')" title="Total tracked MACs — click for per-switch breakdown">
          <div class="sc-label">Tracked MACs</div><div class="sc-value sc-green" id="st-access-macs">—</div>
          <div class="sc-sub">direct devices</div>
        </div>
        <div class="stat-card"><div class="sc-label">Ports Up</div><div class="sc-value sc-green" id="st-ports">—</div><div class="sc-sub">active links</div></div>
        <div class="stat-card"><div class="sc-label">Alarms</div><div class="sc-value sc-orange" id="st-alarms">—</div><div class="sc-sub">unacknowledged</div></div>
      </div>

      <!-- Main grid -->
      <div class="nac-grid">

        <!-- Switch fleet panel -->
        <div class="nac-panel">
          <div class="panel-header">
            <h3><i data-lucide="server" class="icon-lucide"></i> Switch Fleet</h3>
            <div style="display:flex;gap:8px;align-items:center;">
              <span class="poll-indicator" id="lastPollInfo"></span>
            </div>
          </div>
          <!-- Single global legend for port bar colors -->
          <div class="portbar-legend">
            <strong style="font-size:.68rem;margin-right:4px;color:var(--nac-text)">Port colours:</strong>
            <span><span class="pbl-dot" style="background:var(--nac-green)"></span>connected</span>
            <span><span class="pbl-dot" style="background:var(--nac-accent)"></span>trunk</span>
            <span><span class="pbl-dot" style="background:var(--nac-purple)"></span>LAG</span>
            <span><span class="pbl-dot" style="background:var(--nac-red);opacity:.55"></span>no link</span>
            <span><span class="pbl-dot" style="background:#3d4450"></span>shutdown</span>
          </div>
          <!-- Filter bar -->
          <div class="fleet-filter">
            <div class="fleet-filter-wrap">
              <i data-lucide="filter" class="icon-lucide"></i>
              <input type="text" id="fleetFilter" placeholder="Filter by name, IP, model…" oninput="filterFleet()">
            </div>
            <select id="fleetStatusFilter" onchange="filterFleet()">
              <option value="">All status</option>
              <option value="ok">Online</option>
              <option value="error">Error</option>
            </select>
            <select id="fleetGroupFilter" onchange="filterFleet()">
              <option value="">All groups</option>
            </select>
          </div>
          <div class="panel-body" style="padding:10px 12px">
            <div id="switchList" class="switch-list">
              <div class="empty-state">
                <i data-lucide="network" class="icon-lucide"></i>
                <div style="font-size:.9rem;margin-bottom:6px;">No switches configured</div>
                <div style="font-size:.8rem;">Click <strong>Add Switch</strong> to get started</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right column: alarms -->
        <div style="display:flex;flex-direction:column;gap:14px;">

          <!-- Setup guide (shown when no switches) -->
          <div class="setup-guide" id="setupGuide">
            <h4><i class="fas fa-key" style="margin-right:5px"></i> SSH Key Setup (One-time)</h4>
            <div class="setup-step"><div class="step-num">1</div>
              <div>Generate SSH key on Janusserver:<br>
              <code class="step-cmd">ssh-keygen -t ed25519 -f /etc/janus/.ssh/nac_key -N ""</code></div></div>
            <div class="setup-step"><div class="step-num">2</div>
              <div>Copy pubkey to each Juniper switch:<br>
              <code class="step-cmd">cat /etc/janus/.ssh/nac_key.pub</code>
              <span style="color:var(--nac-muted)">Then on switch: <code style="color:var(--nac-accent)">set system login user janus-nac authentication ssh-ed25519 "AAAA..."</code></span></div></div>
            <div class="setup-step"><div class="step-num">3</div>
              <div>Or use password auth (stored securely outside webroot)<br>
              <span style="color:var(--nac-muted)">Credentials stored in <code style="color:var(--nac-green)">/etc/janus/switch_credentials.php</code></span></div></div>
          </div>

          <!-- Alarm feed -->
          <div class="nac-panel" style="flex:1;">
            <div class="panel-header">
              <h3><i class="fas fa-bell" style="color:var(--nac-orange)"></i> Live Alarms</h3>
              <span id="alarmCount" style="font-size:.72rem;color:var(--nac-muted);">0 unacked</span>
            </div>
            <div class="panel-body" style="padding:10px;">
              <div class="alarm-feed" id="alarmFeed">
                <div style="text-align:center;padding:20px;color:var(--nac-muted);font-size:.82rem;">
                  <i class="fas fa-check-circle" style="color:var(--nac-green);font-size:1.5rem;display:block;margin-bottom:6px;"></i>
                  No active alarms
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>


      <!-- ═══════════ ANALYTICS PANEL ═══════════ -->
      <div id="analyticsPanel" style="display:none;margin-top:20px;">
        <div class="nac-panel">
          <div class="panel-header" style="gap:12px">
            <h3><i data-lucide="chart-network" class="icon-lucide"></i> Network Analytics</h3>
            <div style="display:flex;gap:6px;margin-left:auto">
              <button class="btn btn-ghost btn-sm" id="analytics-tab-topo"    onclick="showAnalyticsTab('topo')"    style="font-size:.75rem">🔗 Topology</button>
              <button class="btn btn-ghost btn-sm" id="analytics-tab-vlan"    onclick="showAnalyticsTab('vlan')"    style="font-size:.75rem">🏷 VLANs</button>
              <button class="btn btn-ghost btn-sm" id="analytics-tab-access"  onclick="showAnalyticsTab('access')"  style="font-size:.75rem">🖥 Access MACs</button>
              <button class="btn btn-ghost btn-sm" id="analytics-tab-trunk"   onclick="showAnalyticsTab('trunk')"   style="font-size:.75rem">🔀 Trunk MACs</button>
              <button class="btn btn-ghost btn-sm" id="analytics-tab-unknown" onclick="showAnalyticsTab('unknown')" style="font-size:.75rem">⚠ Unknown</button>
              <button class="btn btn-ghost btn-sm" id="analytics-tab-new"     onclick="showAnalyticsTab('new')"     style="font-size:.75rem">🆕 New MACs</button>
              <button class="btn btn-ghost btn-sm" id="analytics-tab-rogue"   onclick="showAnalyticsTab('rogue')"   style="font-size:.75rem">🚨 Multi-MAC</button>
              <button class="btn btn-ghost btn-sm" onclick="document.getElementById('analyticsPanel').style.display='none'" style="font-size:.75rem;margin-left:8px">✕ Close</button>
            </div>
          </div>
          <div class="panel-body" style="padding:16px">
            <div id="analytics-loading" style="text-align:center;padding:30px;color:var(--nac-muted)">
              <div class="spinner" style="display:inline-block;margin-right:8px"></div>Loading analytics…
            </div>

            <!-- Topology tab -->
            <div id="analytics-topo" style="display:none">
              <div style="font-size:.75rem;color:var(--nac-muted);margin-bottom:10px">
                Switch-to-switch trunk connections discovered via LLDP/CDP
              </div>
              <div id="analytics-topo-content"></div>
            </div>

            <!-- VLAN tab -->
            <div id="analytics-vlan" style="display:none">
              <div style="font-size:.75rem;color:var(--nac-muted);margin-bottom:10px">
                Top VLANs by MAC count across the entire fleet
              </div>
              <div id="analytics-vlan-content"></div>
            </div>

            <!-- Access MACs tab -->
            <div id="analytics-access" style="display:none">
              <div style="font-size:.75rem;color:var(--nac-muted);margin-bottom:10px">
                MACs on access ports — directly connected end devices, per switch
              </div>
              <div id="analytics-access-content"></div>
            </div>

            <!-- Trunk MACs tab -->
            <div id="analytics-trunk" style="display:none">
              <div style="font-size:.75rem;color:var(--nac-muted);margin-bottom:10px">
                MACs seen via trunk ports — devices behind downstream switches
              </div>
              <div id="analytics-trunk-content"></div>
            </div>

            <!-- Unknown upstream tab -->
            <div id="analytics-unknown" style="display:none">
              <div style="font-size:.75rem;color:var(--nac-orange);margin-bottom:10px">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                MACs on trunk ports with no known upstream switch — these devices are behind
                switches you haven't added to Janusyet. Consider adding those switches.
              </div>
              <div id="analytics-unknown-content"></div>
            </div>

            <!-- New MACs 24h tab -->
            <div id="analytics-new" style="display:none">
              <div style="font-size:.75rem;color:var(--nac-muted);margin-bottom:10px">
                New MAC addresses first seen in the last 24 hours
              </div>
              <div id="analytics-new-content"></div>
            </div>

            <!-- Multi-MAC / rogue port tab -->
            <div id="analytics-rogue" style="display:none">
              <div style="font-size:.75rem;color:var(--nac-orange);margin-bottom:10px">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                Access ports with 2+ MACs — possible unmanaged switches, hubs, or DHCP pools
              </div>
              <div id="analytics-rogue-content"></div>
            </div>
          </div>
        </div>
      </div>

</div><!-- /main-content -->

<!-- ═══════════════ GROUP MANAGER MODAL ═══════════════ -->
<div class="nac-modal-overlay" id="groupManagerModal">
  <div class="nac-modal-box" style="max-width:560px">
    <div class="nac-modal-header">
      <h2><i class="fas fa-layer-group" style="color:var(--nac-accent);margin-right:6px"></i> Switch Groups</h2>
      <button class="nac-modal-close" onclick="closeModal('groupManagerModal')">&times;</button>
    </div>
    <div class="nac-modal-body">
      <!-- Add/Edit group form -->
      <div style="background:var(--nac-bg);border:1px solid var(--nac-border);border-radius:8px;padding:14px;margin-bottom:16px">
        <div style="font-size:.78rem;font-weight:600;margin-bottom:10px;color:var(--nac-text)" id="groupFormTitle">
          <i class="fas fa-plus-circle" style="color:var(--nac-accent);margin-right:5px"></i>New Group
        </div>
        <input type="hidden" id="grp-id" value="0">
        <div class="form-grid-2">
          <div class="form-row">
            <label>Group Name *</label>
            <input type="text" id="grp-name" placeholder="Floor 1, DataCenter, etc.">
          </div>
          <div class="form-row">
            <label>Color</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="color" id="grp-color" value="#58a6ff" style="width:40px;height:30px;border:1px solid var(--nac-border);border-radius:4px;background:none;cursor:pointer;padding:1px">
              <div style="display:flex;gap:5px;flex-wrap:wrap" id="grp-color-presets"></div>
            </div>
          </div>
        </div>
        <div class="form-grid-2">
          <div class="form-row">
            <label>Sort Order</label>
            <input type="number" id="grp-sort" value="0" min="0" style="width:80px">
          </div>
          <div class="form-row">
            <label>Notes</label>
            <input type="text" id="grp-notes" placeholder="Optional description">
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px">
          <button class="btn btn-primary btn-sm" onclick="submitGroupSave()" id="grpSaveBtn">
            <i data-lucide="save" class="icon-lucide"></i> Save Group
          </button>
          <button class="btn btn-ghost btn-sm" onclick="resetGroupForm()" id="grpCancelBtn" style="display:none">
            Cancel Edit
          </button>
        </div>
      </div>
      <!-- Existing groups list -->
      <div style="font-size:.75rem;font-weight:600;margin-bottom:8px;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px">
        Existing Groups
      </div>
      <div id="groupManagerList" style="display:flex;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto">
        <div style="color:var(--nac-muted);font-size:.82rem;padding:10px 0">Loading…</div>
      </div>
    </div>
    <div class="nac-modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('groupManagerModal')">Close</button>
    </div>
  </div>
</div>

<!-- ═══════════════ ADD SWITCH MODAL ═══════════════ -->
<div class="nac-modal-overlay" id="addSwitchModal">
  <div class="nac-modal-box">
    <div class="nac-modal-header">
      <h2><i class="fas fa-plus-circle" style="color:var(--nac-accent);margin-right:6px"></i> Add Switch</h2>
      <button class="nac-modal-close" onclick="closeModal('addSwitchModal')">&times;</button>
    </div>
    <div class="nac-modal-body">
      <div class="form-grid-2">
        <div class="form-row">
          <label>Hostname / Label *</label>
          <input type="text" id="sw-hostname" placeholder="CF-Core-Switch">
        </div>
        <div class="form-row">
          <label>Management IP *</label>
          <input type="text" id="sw-ip" placeholder="192.168.1.6">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label>Model</label>
          <input type="text" id="sw-model" placeholder="EX4300-48T">
        </div>
        <div class="form-row">
          <label>Platform / OS *</label>
          <select id="sw-platform">
            <optgroup label="── SNMP recommended ──">
              <option value="junos">Juniper JunOS (EX2200/EX3300/EX4300)</option>
              <option value="junos-new">Juniper JunOS New (EX2300/EX4600/QFX)</option>
              <option value="procurve">HP ProCurve (2510/2610/2910)</option>
              <option value="aruba-os">HP Aruba 2930 / 2920 / 3810</option>
            </optgroup>
            <optgroup label="── SSH recommended ──">
              <option value="ios">Cisco IOS (2960/3560/3750) — SSH</option>
              <option value="ios-xe">Cisco IOS-XE (Catalyst 9k) — SSH</option>
            </optgroup>
            <optgroup label="── Telnet (legacy) ──">
              <option value="ios-legacy">Cisco IOS Legacy (2950/2955 IOS 12.1) — Telnet</option>
              <option value="ios-telnet">Cisco IOS Telnet (2960/3560 IOS 12.2+) — Telnet</option>
            </optgroup>
          </select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label>SSH Username *</label>
          <input type="text" id="sw-user" placeholder="sarbabali">
        </div>
        <div class="form-row">
          <label>SSH Password *</label>
          <input type="password" id="sw-pass" placeholder="••••••••">
          <div class="form-hint">Stored securely in /etc/janus/ (outside webroot)</div>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label>Connection Type</label>
          <select id="sw-conntype" onchange="nacToggleConnFields('add')">
            <option value="snmp" selected>SNMP v2c (recommended)</option>
            <option value="ssh">SSH / password</option>
            <option value="telnet">Telnet (legacy only)</option>
          </select>
        </div>
        <div class="form-row" id="sw-enablepass-row" style="display:none">
          <label>Enable Password <span style="color:var(--nac-muted);font-weight:400">(Cisco enable secret)</span></label>
          <input type="password" id="sw-enablepass" placeholder="Same as login if blank">
          <div class="form-hint">Used after login to enter privileged exec mode (#)</div>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label id="sw-port-label">SSH Port</label>
          <input type="number" id="sw-sshport" value="22">
        </div>
        <div class="form-row">
          <label>Location</label>
          <input type="text" id="sw-location" placeholder="Datacenter Rack A">
        </div>
      </div>
      <div class="form-row">
        <label>Notes</label>
        <textarea id="sw-notes" rows="2" placeholder="Optional notes…"></textarea>
      </div>
      <div class="form-row">
        <label>Assign to Group <span style="color:var(--nac-muted);font-weight:400">(optional)</span></label>
        <select id="sw-group" class="assign-grp">
          <option value="0">— No group —</option>
        </select>
      </div>

      <div class="ssh-key-box" id="sw-ssh-hint" style="display:none">
        <strong style="color:var(--nac-accent);"><i data-lucide="info" class="icon-lucide"></i> SSH Method</strong><br>
        Password auth is used via <code>expect</code>. If not installed:<br>
        <code>sudo apt install expect</code><br>
        Or use key-based auth: generate key with <code>ssh-keygen -t ed25519 -f /etc/janus/.ssh/nac_key -N ""</code>
        then add pubkey to switch.
      </div>
      <div class="ssh-key-box" id="sw-telnet-hint" style="display:none;border-color:rgba(249,115,22,.3);background:rgba(249,115,22,.06)">
        <strong style="color:var(--nac-orange);"><i data-lucide="triangle-alert" class="icon-lucide"></i> Telnet — Legacy Mode</strong><br>
        Telnet transmits credentials in plaintext. Use only on isolated management networks.<br>
        Requires <code>expect</code>: <code>sudo apt install expect</code><br>
        The switch must have <strong>line vty</strong> telnet access configured and optionally an
        <strong>enable password</strong> set.
      </div>

      <!-- ── SNMP Fields (shown only when SNMP selected) ── -->
      <div id="sw-snmp-fields" style="display:none;">
        <div class="form-grid-2">
          <div class="form-row">
            <label>Community String <span style="color:var(--nac-red)">*</span></label>
            <input type="text" id="sw-snmp-community" placeholder="public" value="public" autocomplete="off"
                   style="font-family:monospace">
            <div class="form-hint">SNMPv2c read-only community string on the switch</div>
          </div>
          <div class="form-row">
            <label>SNMP Port</label>
            <input type="number" id="sw-snmp-port" value="161" min="1" max="65535">
          </div>
        </div>
        <div class="form-row">
          <button type="button" id="sw-snmp-test-btn"
                  onclick="nacTestSnmp('add')"
                  style="padding:5px 14px;background:rgba(88,166,255,.12);color:var(--nac-accent);
                         border:1px solid rgba(88,166,255,.3);border-radius:6px;cursor:pointer;font-size:.82rem">
            <i class="fas fa-satellite-dish" style="margin-right:5px"></i>Test SNMP Connectivity
          </button>
          <span id="sw-snmp-test-result" style="margin-left:10px;font-size:.8rem"></span>
        </div>
        <div class="ssh-key-box" style="border-color:rgba(88,166,255,.3);background:rgba(88,166,255,.06)">
          <strong style="color:var(--nac-accent);"><i data-lucide="info" class="icon-lucide"></i> SNMP — Agentless Mode</strong><br>
          No SSH credentials needed. The switch must have SNMP v2c enabled.<br>
          Cisco: <code>snmp-server community public ro</code><br>
          JunOS: <code>set snmp community public authorization read-only</code>
        </div>
      </div>
      <!-- ── SSH/Telnet credential fields wrapper ── -->
    </div>
    <div class="nac-modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('addSwitchModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitAddSwitch()" id="addSwitchBtn">
        <i data-lucide="plus" class="icon-lucide"></i> Add Switch
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════ EDIT SWITCH MODAL ═══════════════ -->
<div class="nac-modal-overlay" id="editSwitchModal">
  <div class="nac-modal-box">
    <div class="nac-modal-header">
      <h2><i class="fas fa-pencil-alt" style="color:var(--nac-accent);margin-right:6px"></i> Edit Switch</h2>
      <button class="nac-modal-close" onclick="closeModal('editSwitchModal')">&times;</button>
    </div>
    <div class="nac-modal-body">
      <input type="hidden" id="edit-sw-id">
      <input type="hidden" id="edit-sw-ip-hidden">
      <div class="form-grid-2">
        <div class="form-row">
          <label>Hostname / Label *</label>
          <input type="text" id="edit-sw-hostname" placeholder="CF-Core-Switch">
        </div>
        <div class="form-row">
          <label>Management IP</label>
          <input type="text" id="edit-sw-ip" disabled style="opacity:.5;cursor:not-allowed;">
          <div class="form-hint">IP cannot be changed — delete and re-add to change IP</div>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label>Model</label>
          <input type="text" id="edit-sw-model" placeholder="EX4300-48T">
        </div>
        <div class="form-row">
          <label>Platform / OS *</label>
          <select id="edit-sw-platform">
            <optgroup label="── SNMP recommended ──">
              <option value="junos">Juniper JunOS (EX2200/EX3300/EX4300)</option>
              <option value="junos-new">Juniper JunOS New (EX2300/EX4600/QFX)</option>
              <option value="procurve">HP ProCurve (2510/2610/2910)</option>
              <option value="aruba-os">HP Aruba 2930 / 2920 / 3810</option>
            </optgroup>
            <optgroup label="── SSH recommended ──">
              <option value="ios">Cisco IOS (2960/3560/3750) — SSH</option>
              <option value="ios-xe">Cisco IOS-XE (Catalyst 9k) — SSH</option>
            </optgroup>
            <optgroup label="── Telnet (legacy) ──">
              <option value="ios-legacy">Cisco IOS Legacy (2950/2955 IOS 12.1) — Telnet</option>
              <option value="ios-telnet">Cisco IOS Telnet (2960/3560 IOS 12.2+) — Telnet</option>
            </optgroup>
          </select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label>SSH Username *</label>
          <input type="text" id="edit-sw-user" placeholder="sarbabali">
        </div>
        <div class="form-row">
          <label>New Password <span style="color:var(--nac-muted);font-weight:400">(leave blank to keep current)</span></label>
          <input type="password" id="edit-sw-pass" placeholder="Leave blank to keep existing">
          <div class="form-hint">Only fill this if you want to update the password</div>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label>Connection Type</label>
          <select id="edit-sw-conntype" onchange="nacToggleConnFields('edit')">
            <option value="ssh">SSH (recommended)</option>
            <option value="telnet">Telnet (legacy only)</option>
            <option value="snmp">SNMP v2c (agentless)</option>
          </select>
        </div>
        <div class="form-row" id="edit-sw-enablepass-row" style="display:none">
          <label>Enable Password <span style="color:var(--nac-muted);font-weight:400">(leave blank to keep current)</span></label>
          <input type="password" id="edit-sw-enablepass" placeholder="Leave blank to keep existing">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-row">
          <label>Location</label>
          <input type="text" id="edit-sw-location" placeholder="Datacenter Rack A">
        </div>
        <div class="form-row">
          <label>Enabled</label>
          <select id="edit-sw-enabled">
            <option value="1">Yes – include in polling</option>
            <option value="0">No – disable polling</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <label>Notes</label>
        <textarea id="edit-sw-notes" rows="2" placeholder="Optional notes…"></textarea>
      </div>
      <div class="form-row">
        <label>Group <span style="color:var(--nac-muted);font-weight:400">(optional)</span></label>
        <select id="edit-sw-group" class="assign-grp">
          <option value="0">— No group —</option>
        </select>
      </div>

      <!-- ── SNMP Fields (shown only when SNMP selected) ── -->
      <div id="edit-sw-snmp-fields" style="display:none;">
        <div class="form-grid-2">
          <div class="form-row">
            <label>Community String</label>
            <input type="text" id="edit-sw-snmp-community" placeholder="public" autocomplete="off"
                   style="font-family:monospace">
          </div>
          <div class="form-row">
            <label>SNMP Port</label>
            <input type="number" id="edit-sw-snmp-port" value="161" min="1" max="65535">
          </div>
        </div>
        <div class="form-row">
          <button type="button" id="edit-sw-snmp-test-btn"
                  onclick="nacTestSnmp('edit')"
                  style="padding:5px 14px;background:rgba(88,166,255,.12);color:var(--nac-accent);
                         border:1px solid rgba(88,166,255,.3);border-radius:6px;cursor:pointer;font-size:.82rem">
            <i class="fas fa-satellite-dish" style="margin-right:5px"></i>Test SNMP Connectivity
          </button>
          <span id="edit-sw-snmp-test-result" style="margin-left:10px;font-size:.8rem"></span>
        </div>
      </div>

    </div>
    <div class="nac-modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('editSwitchModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitEditSwitch()" id="editSwitchBtn">
        <i data-lucide="save" class="icon-lucide"></i> Save Changes
      </button>
    </div>
  </div>
</div>
<div class="nac-modal-overlay" id="deleteSwitchModal">
  <div class="nac-modal-box" style="max-width:420px;">
    <div class="nac-modal-header">
      <h2 style="color:var(--nac-red)"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i> Delete Switch</h2>
      <button class="nac-modal-close" onclick="closeModal('deleteSwitchModal')">&times;</button>
    </div>
    <div class="nac-modal-body">
      <p style="margin-bottom:12px;">You are about to permanently delete:</p>
      <div style="background:var(--nac-bg);border:1px solid var(--nac-border);border-radius:6px;padding:12px;margin-bottom:16px;">
        <strong id="del-sw-name" style="font-size:1rem;"></strong><br>
        <span id="del-sw-ip" style="font-family:monospace;font-size:.82rem;color:var(--nac-muted);"></span>
      </div>
      <div style="background:rgba(248,81,73,.08);border:1px solid rgba(248,81,73,.3);border-radius:6px;padding:12px;font-size:.82rem;">
        <i class="fas fa-exclamation-circle" style="color:var(--nac-red);margin-right:5px"></i>
        <strong>This will permanently delete:</strong>
        <ul style="margin:6px 0 0 18px;color:var(--nac-muted);">
          <li>All port records for this switch</li>
          <li>All MAC address entries</li>
          <li>All event history and alarms</li>
          <li>Stored SSH credentials</li>
        </ul>
      </div>
    </div>
    <div class="nac-modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('deleteSwitchModal')">Cancel</button>
      <button class="btn btn-danger" onclick="submitDeleteSwitch()" id="deleteSwitchBtn">
        <i data-lucide="trash" class="icon-lucide -alt"></i> Delete Permanently
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════ SWITCH DETAIL PANEL ═══════════════ -->
<div class="detail-panel" id="detailPanel">
  <div class="detail-panel-header">
    <div>
      <div id="dp-title" style="font-weight:700;font-size:1rem;"></div>
      <div id="dp-sub" style="font-size:.75rem;color:var(--nac-muted);margin-top:1px;font-family:monospace;"></div>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-ghost btn-sm" id="dp-poll-btn" onclick="pollCurrentSwitch()">
        <i data-lucide="refresh-ccw" class="icon-lucide -alt"></i> Poll Now
      </button>
      <button class="btn btn-ghost btn-sm" onclick="closeDetailPanel()">&times; Close</button>
    </div>
  </div>
  <div class="detail-panel-body">
    <div class="detail-tabs">
      <button class="dtab active" onclick="showDTab('ports',this)">
        <i data-lucide="ethernet" class="icon-lucide"></i> Ports
      </button>
      <button class="dtab" onclick="showDTab('macs',this)">
        <i data-lucide="fingerprint" class="icon-lucide"></i> MACs
      </button>
      <button class="dtab" onclick="showDTab('events',this)">
        <i data-lucide="bell" class="icon-lucide"></i> Events
      </button>
    </div>
    <div id="dp-ports"></div>
    <div id="dp-macs"   style="display:none"></div>
    <div id="dp-events" style="display:none"></div>
  </div>
</div>
<div id="panelBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:7999;"
     onclick="closeDetailPanel()"></div>

<script>
/* ═══════════════════════════════════════
   NAC Dashboard JS
═══════════════════════════════════════ */
let currentSwitchId = null;
let refreshTimer    = null;
window._cachedGroups   = [];
window._cachedSwitches = [];

// ── Bootstrap ──
document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();
  setupSearch();
  seedGroupColorPresets();
  refreshTimer = setInterval(loadDashboard, 30000);
  // Apply SNMP default state to Add Switch modal on page load
  nacToggleConnFields('add');
  const sb = document.getElementById('sidebar');
  const mc = document.getElementById('main-content');
  if (sb && mc) {
    new MutationObserver(() => {
      mc.style.marginLeft = sb.classList.contains('collapsed') ? '64px' : '260px';
    }).observe(sb, { attributes: true, attributeFilter: ['class'] });
  }
});

// ── Load dashboard stats + switch list ──
async function loadDashboard() {
  const [stats, switches, groups] = await Promise.all([
    nacAjax('dashboard_stats'),
    nacAjax('list_switches'),
    nacAjax('group_list'),
  ]);
  if (stats && !stats.error) renderStats(stats);
  if (groups && !groups.error) {
    window._cachedGroups = groups || [];
    populateGroupFilters(groups);
    populateGroupSelects(groups);
  }
  if (switches && !switches.error) {
    window._cachedSwitches = switches;
    renderSwitches(switches);
  }
}

function renderStats(s) {
  setText('st-total',        s.total_switches);
  setText('st-ok',           s.ok_switches);
  setText('st-err',          s.error_switches);
  const trackedMacs = (s.access_macs != null || s.trunk_macs != null)
    ? ((s.access_macs ?? 0) + (s.trunk_macs ?? 0))
    : '—';
  setText('st-access-macs', trackedMacs);
  setText('st-ports',        s.ports_up);
  setText('st-alarms',       s.unacked_alarms);
  setText('alarmCount', s.unacked_alarms + ' unacked');
  renderAlarmFeed(s.recent_alarms || []);
}

function renderAlarmFeed(alarms) {
  const feed = document.getElementById('alarmFeed');
  if (!alarms.length) {
    feed.innerHTML = `<div style="text-align:center;padding:20px;color:var(--nac-muted);font-size:.82rem;">
      <i class="fas fa-check-circle" style="color:var(--nac-green);font-size:1.5rem;display:block;margin-bottom:6px;"></i>No active alarms</div>`;
    return;
  }
  const icons = {
    mac_moved:'fa-arrows-alt', new_mac:'fa-plus-circle', vlan_change:'fa-tag',
    port_down:'fa-times-circle', port_up:'fa-check-circle',
    multi_mac:'fa-sitemap', mac_lost:'fa-ghost'
  };
  const colors = {
    mac_moved:'var(--nac-orange)', new_mac:'var(--nac-accent)', vlan_change:'var(--nac-yellow)',
    port_down:'var(--nac-red)', port_up:'var(--nac-green)',
    multi_mac:'var(--nac-purple)', mac_lost:'var(--nac-muted)'
  };

  feed.innerHTML = alarms.map(a => {
    const color = colors[a.event_type] || 'var(--nac-muted)';
    const icon  = icons[a.event_type]  || 'fa-bell';
    // Build a compact context line
    let ctx = '';
    if (a.mac)      ctx += `<span class="mac-mono" style="font-size:.68rem">${escHtml(a.mac)}</span> `;
    if (a.new_port) ctx += `→ <strong>${escHtml(a.new_port)}</strong> `;
    if (a.new_vlan) ctx += `<span class="vlan-badge" style="font-size:.65rem">${escHtml(a.new_vlan)}</span>`;

    return `
    <div class="alarm-item alarm-${a.event_type}" id="alarm-${a.id}"
         style="cursor:pointer" onclick="openAlarmDetail(${JSON.stringify(a).replace(/"/g,'&quot;')})">
      <div style="display:flex;align-items:flex-start;gap:8px">
        <i class="fas ${icon}" style="color:${color};margin-top:2px;flex-shrink:0"></i>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:6px">
            <span class="alarm-type" style="font-size:.8rem">${fmtEventType(a.event_type)}</span>
            <span style="color:var(--nac-muted);font-size:.7rem">${escHtml(a.switch_name||'')}</span>
            <span style="font-size:.7rem;color:var(--nac-muted);margin-left:auto">${timeAgo(a.created_at)}</span>
          </div>
          ${ctx ? `<div style="margin-top:2px;font-size:.72rem;color:var(--nac-muted)">${ctx}</div>` : ''}
          <div class="alarm-detail" style="margin-top:2px">${escHtml(a.details||'')}</div>
        </div>
        <button class="alarm-ack-btn" onclick="event.stopPropagation();ackAlarm(${a.id})" title="Acknowledge">✓</button>
      </div>
    </div>`;
  }).join('');
}

function openAlarmDetail(alarm) {
  const existing = document.getElementById('alarmDetailModal');
  if (existing) existing.remove();

  const colors = {
    mac_moved:'var(--nac-orange)', new_mac:'var(--nac-accent)', vlan_change:'var(--nac-yellow)',
    port_down:'var(--nac-red)', port_up:'var(--nac-green)',
    multi_mac:'var(--nac-purple)', mac_lost:'var(--nac-muted)'
  };
  const color = colors[alarm.event_type] || 'var(--nac-muted)';

  // Build structured change table
  const rows = [];
  if (alarm.switch_name) rows.push(['Switch', `<strong>${escHtml(alarm.switch_name)}</strong>`]);
  if (alarm.mac)         rows.push(['MAC', `<span class="mac-mono">${escHtml(alarm.mac)}</span>`]);
  if (alarm.old_port || alarm.new_port) {
    const from = alarm.old_port ? escHtml(alarm.old_port) : '—';
    const to   = alarm.new_port ? `<strong style="color:var(--nac-green)">${escHtml(alarm.new_port)}</strong>` : '—';
    if (alarm.old_port && alarm.new_port && alarm.old_port !== alarm.new_port)
      rows.push(['Port', `${from} → ${to}`]);
    else
      rows.push(['Port', alarm.new_port ? to : from]);
  }
  if (alarm.old_vlan || alarm.new_vlan) {
    const from = alarm.old_vlan ? `<span class="vlan-badge">${escHtml(alarm.old_vlan)}</span>` : '—';
    const to   = alarm.new_vlan ? `<span class="vlan-badge" style="background:rgba(34,197,94,.15)">${escHtml(alarm.new_vlan)}</span>` : '—';
    if (alarm.old_vlan && alarm.new_vlan && alarm.old_vlan !== alarm.new_vlan)
      rows.push(['VLAN', `${from} → ${to}`]);
    else
      rows.push(['VLAN', alarm.new_vlan ? to : from]);
  }
  rows.push(['Time', timeAgo(alarm.created_at) + (alarm.created_at ? ` <span style="color:var(--nac-muted);font-size:.7rem">(${alarm.created_at})</span>` : '')]);

  const tableHtml = rows.map(([k,v]) =>
    `<tr><td style="color:var(--nac-muted);font-size:.75rem;padding:5px 10px 5px 0;white-space:nowrap;vertical-align:top">${k}</td>
         <td style="font-size:.8rem;padding:5px 0">${v}</td></tr>`
  ).join('');

  const modal = document.createElement('div');
  modal.id = 'alarmDetailModal';
  modal.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;
    display:flex;align-items:center;justify-content:center`;
  modal.innerHTML = `
    <div style="background:var(--nac-card);border:1px solid var(--nac-border);border-radius:12px;
         width:500px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.4)">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
        <div>
          <div style="font-weight:700;font-size:.95rem;color:${color}">
            ${fmtEventType(alarm.event_type)}
          </div>
          <div style="font-size:.75rem;color:var(--nac-muted);margin-top:3px">${escHtml(alarm.details||'')}</div>
        </div>
        <button onclick="document.getElementById('alarmDetailModal').remove()"
          style="background:none;border:none;color:var(--nac-muted);font-size:1.3rem;cursor:pointer;flex-shrink:0">✕</button>
      </div>

      <table style="width:100%;border-collapse:collapse;margin-bottom:16px">${tableHtml}</table>

      ${alarm.mac ? `
      <div style="border-top:1px solid var(--nac-border);padding-top:14px;margin-top:4px">
        <div style="font-size:.72rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;font-weight:600">
          <i class="fas fa-history" style="margin-right:4px"></i> MAC History
        </div>
        <div id="adm-history" style="font-size:.78rem;color:var(--nac-muted)">Loading…</div>
      </div>
      <div style="border-top:1px solid var(--nac-border);padding-top:12px;margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <button onclick="traceMac('${escHtml(alarm.mac)}',this);document.getElementById('alarmDetailModal').remove()"
          style="font-size:.78rem;padding:5px 12px;background:rgba(59,130,246,.15);color:var(--nac-accent);
                 border:1px solid rgba(59,130,246,.3);border-radius:6px;cursor:pointer">
          🔍 Trace MAC
        </button>
        <button onclick="ackAlarm(${alarm.id});document.getElementById('alarmDetailModal').remove()"
          style="font-size:.78rem;padding:5px 12px;background:rgba(34,197,94,.15);color:var(--nac-green);
                 border:1px solid rgba(34,197,94,.3);border-radius:6px;cursor:pointer">
          ✓ Acknowledge
        </button>
      </div>` : `
      <div style="border-top:1px solid var(--nac-border);padding-top:12px;margin-top:4px;display:flex;gap:8px">
        <button onclick="ackAlarm(${alarm.id});document.getElementById('alarmDetailModal').remove()"
          style="font-size:.78rem;padding:5px 12px;background:rgba(34,197,94,.15);color:var(--nac-green);
                 border:1px solid rgba(34,197,94,.3);border-radius:6px;cursor:pointer">
          ✓ Acknowledge
        </button>
      </div>`}
    </div>`;
  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);

  // Load MAC history if we have a MAC
  if (alarm.mac) {
    nacAjax('mac_history', { mac: alarm.mac }).then(events => {
      const hEl = document.getElementById('adm-history');
      if (!hEl) return;
      if (!events || !events.length) {
        hEl.textContent = 'No history found.';
        return;
      }
      const typeLabels = {
        mac_moved:'Moved', new_mac:'Appeared', vlan_change:'VLAN Change',
        port_down:'Port Down', port_up:'Port Up', mac_lost:'Lost', multi_mac:'Multi-MAC'
      };
      hEl.innerHTML = events.slice(0, 20).map(ev => `
        <div style="display:flex;gap:8px;padding:4px 0;border-bottom:1px solid var(--nac-border);align-items:flex-start">
          <span style="color:var(--nac-muted);font-size:.68rem;white-space:nowrap;margin-top:1px">${timeAgo(ev.created_at)}</span>
          <span style="font-weight:600;font-size:.72rem;white-space:nowrap">${typeLabels[ev.event_type]||ev.event_type}</span>
          <span style="color:var(--nac-muted);font-size:.7rem;font-family:monospace">${escHtml(ev.details||'')}</span>
          ${ev.acknowledged ? `<span style="color:var(--nac-green);font-size:.65rem;white-space:nowrap;margin-left:auto">✓ Acked</span>` : ''}
        </div>`).join('');
    });
  }
}

function buildSwitchCard(sw) {
  const statusClass = sw.poll_status === 'ok' ? 'up' :
                      sw.poll_status === 'pending' ? 'pending' :
                      sw.poll_status === 'error' ? 'error' : 'down';
  const hasAlarms = parseInt(sw.alarms) > 0;
  const lastPoll  = sw.last_polled ? timeAgo(sw.last_polled) : 'Never';
  const groupDot  = sw.group_color
    ? `<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:${escHtml(sw.group_color)};margin-right:4px;flex-shrink:0"></span>`
    : '';

  return `<div class="switch-card ${hasAlarms?'has-alarms':''} ${sw.poll_status==='error'?'offline':''}"
       data-id="${sw.id}" data-hostname="${escHtml(sw.hostname).toLowerCase()}"
       data-ip="${sw.ip}" data-model="${escHtml(sw.model||'').toLowerCase()}"
       data-status="${sw.poll_status}" data-group="${sw.group_id||0}"
       onclick="openSwitchDetail(${sw.id},'${escHtml(sw.hostname)}','${sw.ip}')">
    ${hasAlarms ? `<div class="sc-alarm-badge">${sw.alarms} alarm${sw.alarms>1?'s':''}</div>` : ''}
    <div class="sc-card-actions" onclick="event.stopPropagation()">
      <button class="sc-action-btn" title="Edit" onclick="openEditSwitch(${sw.id})">
        <i data-lucide="pencil" class="icon-lucide"></i>
      </button>
      <button class="sc-action-btn sc-action-delete" title="Delete" onclick="confirmDeleteSwitch(${sw.id},'${escHtml(sw.hostname)}')">
        <i data-lucide="trash" class="icon-lucide -alt"></i>
      </button>
    </div>
    <div class="sc-row1">
      <div class="sc-status-dot ${statusClass}"></div>
      ${groupDot}
      <div class="sc-hostname">${escHtml(sw.hostname)}</div>
      <div class="sc-ip">${sw.ip}</div>
      ${sw.model ? `<div class="sc-model">${escHtml(sw.model)}</div>` : ''}
    </div>
    <div class="sc-row2">
      <span><i data-lucide="ethernet" class="icon-lucide"></i>${sw.port_count||0} ports</span>
      <span><i data-lucide="fingerprint" class="icon-lucide"></i>${sw.mac_count||0} MACs</span>
      <span title="${sw.poll_duration ? sw.poll_duration+'s poll time' : ''}">
        <i data-lucide="clock" class="icon-lucide"></i>${lastPoll}${sw.poll_duration ? ` <span style="color:var(--nac-muted);font-size:.65rem">(${sw.poll_duration}s)</span>` : ''}
      </span>
      ${sw.connection_type === 'telnet'
        ? `<span style="color:var(--nac-orange);font-size:.68rem;background:rgba(249,115,22,.1);padding:1px 5px;border-radius:3px"><i data-lucide="terminal" class="icon-lucide"></i> Telnet</span>`
        : sw.connection_type === 'snmp'
          ? `<span style="color:var(--nac-accent);font-size:.68rem;background:rgba(88,166,255,.1);padding:1px 5px;border-radius:3px"><i data-lucide="satellite" class="icon-lucide"></i> SNMP</span>`
          : `<span style="color:var(--nac-green);font-size:.68rem;background:rgba(63,185,80,.1);padding:1px 5px;border-radius:3px"><i data-lucide="lock" class="icon-lucide"></i> SSH</span>`}
      ${sw.poll_status==='error'
        ? `<span style="color:var(--nac-red)" title="${escHtml(sw.poll_error||'')}">
             <i data-lucide="triangle-alert" class="icon-lucide"></i>${escHtml((sw.poll_error||'').substring(0,45))}
           </span>`
        : ''}
    </div>
    <div class="sc-portbar" id="portbar-${sw.id}"></div>
  </div>`;
}

function renderSwitches(switches) {
  const list  = document.getElementById('switchList');
  const guide = document.getElementById('setupGuide');

  if (!switches.length) {
    list.innerHTML = `<div class="empty-state">
      <i data-lucide="network" class="icon-lucide"></i>
      <div style="font-size:.9rem;margin-bottom:6px;">No switches configured</div>
      <div style="font-size:.8rem;">Click <strong>Add Switch</strong> to get started</div>
    </div>`;
    guide.style.display = 'block';
    return;
  }
  guide.style.display = 'none';

  const groups   = window._cachedGroups || [];
  const grouped  = {}; // group_id → [switches]
  const ungrouped= [];

  switches.forEach(sw => {
    const gid = sw.group_id ? String(sw.group_id) : null;
    if (gid) {
      if (!grouped[gid]) grouped[gid] = [];
      grouped[gid].push(sw);
    } else {
      ungrouped.push(sw);
    }
  });

  let html = '';

  // Render each group as a collapsible section
  groups.forEach(g => {
    const members = grouped[String(g.id)] || [];
    const collapsed = localStorage.getItem('grp_collapsed_' + g.id) === '1';
    const totalMACs = members.reduce((s,sw) => s + parseInt(sw.mac_count||0), 0);
    const errors    = members.filter(sw => sw.poll_status === 'error').length;

    html += `<div class="group-section" id="grp-section-${g.id}">
      <div class="group-header ${collapsed?'collapsed':''}" onclick="toggleGroup(${g.id},this)">
        <div class="group-color-bar" style="background:${escHtml(g.color)}"></div>
        <div class="group-title">${escHtml(g.name)}</div>
        <div class="group-meta">
          ${members.length} switch${members.length!==1?'es':''}
          &nbsp;·&nbsp; ${totalMACs} MACs
          ${errors ? `&nbsp;·&nbsp;<span style="color:var(--nac-red)">${errors} error${errors>1?'s':''}</span>` : ''}
        </div>
        <div class="group-btns" onclick="event.stopPropagation()">
          <button class="group-btn" title="Poll all switches in this group"
            onclick="pollGroup(${g.id},this)"><i data-lucide="refresh-ccw" class="icon-lucide -alt"></i></button>
          <button class="group-btn" title="Edit group"
            onclick="editGroupInline(${g.id})"><i data-lucide="pencil" class="icon-lucide"></i></button>
          <button class="group-btn del" title="Delete group (switches kept, ungrouped)"
            onclick="deleteGroup(${g.id},'${escHtml(g.name)}')"><i data-lucide="trash" class="icon-lucide -alt"></i></button>
        </div>
        <i data-lucide="chevron-down" class="icon-lucide group-chevron"></i>
      </div>
      <div class="group-body ${collapsed?'hidden':''}" id="grp-body-${g.id}">
        ${members.length
          ? members.map(sw => buildSwitchCard(sw)).join('')
          : `<div style="color:var(--nac-muted);font-size:.8rem;padding:8px;text-align:center">
               No switches in this group yet. Edit a switch to assign it here.
             </div>`}
      </div>
    </div>`;
  });

  // Ungrouped switches
  if (ungrouped.length) {
    if (groups.length) {
      html += `<div class="ungrouped-label"><i class="fas fa-server" style="margin-right:4px"></i>Ungrouped</div>`;
    }
    html += ungrouped.map(sw => buildSwitchCard(sw)).join('');
  }

  list.innerHTML = html;
  switches.forEach(sw => loadPortBar(sw.id));
}

function toggleGroup(id, headerEl) {
  headerEl.classList.toggle('collapsed');
  const body = document.getElementById('grp-body-' + id);
  body.classList.toggle('hidden');
  localStorage.setItem('grp_collapsed_' + id, body.classList.contains('hidden') ? '1' : '0');
}

async function pollGroup(groupId, btn) {
  btn.innerHTML = '<div class="spinner" style="display:inline-block;width:10px;height:10px;border-width:1px"></div>';
  btn.disabled = true;
  await nacAjax('poll_group', { group_id: groupId });
  btn.innerHTML = '<i data-lucide="refresh-ccw" class="icon-lucide -alt"></i>';
  btn.disabled = false;
  loadDashboard();
}

// ── Filter fleet (instant, no re-fetch) ──
function filterFleet() {
  const q     = (document.getElementById('fleetFilter')?.value || '').toLowerCase().trim();
  const st    = document.getElementById('fleetStatusFilter')?.value || '';
  const gf    = document.getElementById('fleetGroupFilter')?.value || '';

  document.querySelectorAll('.switch-card').forEach(card => {
    const name   = card.dataset.hostname || '';
    const ip     = card.dataset.ip       || '';
    const model  = card.dataset.model    || '';
    const status = card.dataset.status   || '';
    const group  = card.dataset.group    || '0';

    const matchQ  = !q  || name.includes(q) || ip.includes(q) || model.includes(q);
    const matchSt = !st || status === st;
    const matchGr = !gf || group === gf;
    card.style.display = (matchQ && matchSt && matchGr) ? '' : 'none';
  });
}

function populateGroupFilters(groups) {
  const sel = document.getElementById('fleetGroupFilter');
  if (!sel) return;
  const cur = sel.value;
  sel.innerHTML = '<option value="">All groups</option>';
  groups.forEach(g => {
    const opt = document.createElement('option');
    opt.value = g.id; opt.textContent = g.name;
    if (String(g.id) === cur) opt.selected = true;
    sel.appendChild(opt);
  });
}

function populateGroupSelects(groups) {
  // Populate add/edit modal group dropdowns
  ['sw-group', 'edit-sw-group'].forEach(selId => {
    const sel = document.getElementById(selId);
    if (!sel) return;
    const cur = sel.value;
    sel.innerHTML = '<option value="0">— No group —</option>';
    groups.forEach(g => {
      const opt = document.createElement('option');
      opt.value = g.id; opt.textContent = g.name;
      if (String(g.id) === String(cur)) opt.selected = true;
      sel.appendChild(opt);
    });
  });
}

async function loadPortBar(switchId) {
  const data = await nacAjax('switch_detail', { id: switchId });
  if (!data || !data.ports) return;
  const bar = document.getElementById('portbar-' + switchId);
  if (!bar) return;

  const slots = data.ports
    .filter(p => p.port_type !== 'irb' && p.port_type !== 'lag-member' && !p.port_name.startsWith('irb'))
    .slice(0, 64)
    .map(p => {
      let cls = 'down-admin', statusLabel = 'shutdown';
      if (p.admin_status === 'up' && p.link_status === 'up') {
        // FIX: is_trunk comes from DB as string "0"/"1" — must use == not bare truthy
        const isTrunk = p.is_trunk == 1 || p.port_type === 'trunk';
        cls = isTrunk ? 'up-trunk' : (p.port_type === 'lag' ? 'lag' : 'up-access');
        statusLabel = isTrunk ? 'trunk ↑' : 'connected ↑';
      } else if (p.admin_status === 'up') {
        cls = 'down-link'; statusLabel = 'no link';
      }
      const mac  = p.live_macs > 0 ? `${p.live_macs} MAC(s)` : 'no MACs';
      const vlan = p.vlan_names ? ` | ${p.vlan_names}` : '';
      return `<div class="pb-slot ${cls}" data-sw="${switchId}" data-port="${p.port_name}" title="${p.port_name}: ${statusLabel} | ${mac}${vlan}"></div>`;
    }).join('');

  // No per-switch legend — single global legend is in the panel header
  bar.innerHTML = `<div style="display:flex;gap:2px;flex-wrap:wrap;margin-top:6px">${slots}</div>`;
}

// ── Group Manager ──
const GROUP_COLORS = ['#58a6ff','#3fb950','#f97316','#a371f7','#f85149',
                      '#e3b341','#06b6d4','#ec4899','#84cc16','#8b949e'];

function seedGroupColorPresets() {
  const box = document.getElementById('grp-color-presets');
  if (!box) return;
  GROUP_COLORS.forEach(c => {
    const s = document.createElement('span');
    s.style.cssText = `display:inline-block;width:18px;height:18px;border-radius:3px;
      background:${c};cursor:pointer;border:2px solid transparent;transition:transform .1s`;
    s.title = c;
    s.onclick = () => {
      document.getElementById('grp-color').value = c;
      box.querySelectorAll('span').forEach(x => x.style.borderColor='transparent');
      s.style.borderColor = '#fff';
    };
    s.onmouseover = () => { s.style.transform='scale(1.2)'; };
    s.onmouseout  = () => { s.style.transform='scale(1)'; };
    box.appendChild(s);
  });
}

async function openGroupManager() {
  document.getElementById('groupManagerModal').classList.add('show');
  await refreshGroupManagerList();
}

async function refreshGroupManagerList() {
  const list   = document.getElementById('groupManagerList');
  list.innerHTML = '<div style="color:var(--nac-muted);font-size:.8rem;padding:8px">Loading…</div>';
  const groups = await nacAjax('group_list');
  window._cachedGroups = groups || [];
  populateGroupFilters(groups);
  populateGroupSelects(groups);
  if (!groups || !groups.length) {
    list.innerHTML = '<div style="color:var(--nac-muted);font-size:.8rem;padding:8px">No groups yet. Create one above.</div>';
    return;
  }
  list.innerHTML = groups.map(g => `
    <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;
         background:var(--nac-bg);border:1px solid var(--nac-border);border-radius:6px;
         border-left:3px solid ${escHtml(g.color)}">
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:.83rem">${escHtml(g.name)}</div>
        ${g.notes ? `<div style="font-size:.72rem;color:var(--nac-muted)">${escHtml(g.notes)}</div>` : ''}
      </div>
      <span style="font-size:.72rem;color:var(--nac-muted);white-space:nowrap">${g.switch_count} switch${g.switch_count!=1?'es':''}</span>
      <button class="group-btn" onclick="editGroupInline(${g.id})" title="Edit"><i data-lucide="pencil" class="icon-lucide"></i></button>
      <button class="group-btn del" onclick="deleteGroup(${g.id},'${escHtml(g.name)}')" title="Delete"><i data-lucide="trash" class="icon-lucide -alt"></i></button>
    </div>`).join('');
}

function resetGroupForm() {
  document.getElementById('grp-id').value    = '0';
  document.getElementById('grp-name').value  = '';
  document.getElementById('grp-color').value = '#58a6ff';
  document.getElementById('grp-notes').value = '';
  document.getElementById('grp-sort').value  = '0';
  document.getElementById('groupFormTitle').innerHTML =
    '<i class="fas fa-plus-circle" style="color:var(--nac-accent);margin-right:5px"></i>New Group';
  document.getElementById('grpCancelBtn').style.display = 'none';
  document.getElementById('grpSaveBtn').innerHTML = '<i data-lucide="save" class="icon-lucide"></i> Save Group';
}

function editGroupInline(id) {
  const g = (window._cachedGroups||[]).find(x => parseInt(x.id)===parseInt(id));
  if (!g) return;
  // Make sure modal is open
  document.getElementById('groupManagerModal').classList.add('show');
  document.getElementById('grp-id').value    = g.id;
  document.getElementById('grp-name').value  = g.name;
  document.getElementById('grp-color').value = g.color || '#58a6ff';
  document.getElementById('grp-notes').value = g.notes || '';
  document.getElementById('grp-sort').value  = g.sort_order || 0;
  document.getElementById('groupFormTitle').innerHTML =
    `<i class="fas fa-pencil-alt" style="color:var(--nac-accent);margin-right:5px"></i>Editing: ${escHtml(g.name)}`;
  document.getElementById('grpCancelBtn').style.display = '';
  document.getElementById('grpSaveBtn').innerHTML = '<i data-lucide="save" class="icon-lucide"></i> Update Group';
  document.getElementById('grp-name').focus();
}

async function submitGroupSave() {
  const id    = document.getElementById('grp-id').value;
  const name  = document.getElementById('grp-name').value.trim();
  const color = document.getElementById('grp-color').value;
  const notes = document.getElementById('grp-notes').value.trim();
  const sort  = document.getElementById('grp-sort').value;
  if (!name) { alert('Group name is required'); return; }
  const btn = document.getElementById('grpSaveBtn');
  btn.innerHTML = '<div class="spinner" style="display:inline-block;width:12px;height:12px;border-width:2px"></div>';
  btn.disabled = true;
  const r = await nacAjax('group_save', { id, name, color, notes, sort_order: sort });
  btn.innerHTML = id > 0 ? '<i data-lucide="save" class="icon-lucide"></i> Update Group' : '<i data-lucide="save" class="icon-lucide"></i> Save Group';
  btn.disabled = false;
  if (r && r.ok) { resetGroupForm(); await refreshGroupManagerList(); loadDashboard(); }
  else alert('Error: ' + (r?.error || 'Unknown'));
}

async function deleteGroup(id, name) {
  if (!confirm(`Delete group "${name}"?\n\nSwitches in this group will become ungrouped (not deleted).`)) return;
  await nacAjax('group_delete', { id });
  await refreshGroupManagerList();
  loadDashboard();
}

// ── Switch detail panel ──
async function openSwitchDetail(id, hostname, ip) {
  currentSwitchId = id;
  document.getElementById('dp-title').textContent = hostname;
  document.getElementById('dp-sub').textContent = ip;
  document.getElementById('detailPanel').classList.add('open');
  document.getElementById('panelBackdrop').style.display = 'block';
  loadDetailPanel(id);
}

function closeDetailPanel() {
  document.getElementById('detailPanel').classList.remove('open');
  document.getElementById('panelBackdrop').style.display = 'none';
  currentSwitchId = null;
}

async function loadDetailPanel(id) {
  ['dp-ports','dp-macs','dp-events'].forEach(x => {
    document.getElementById(x).innerHTML = '<div style="padding:20px;text-align:center"><div class="spinner"></div></div>';
  });
  const data = await nacAjax('switch_detail', { id });
  if (!data) return;
  renderDetailPorts(data.ports || []);
  renderDetailMacs(data.macs || []);
  renderDetailEvents(data.events || []);
  // Auto-load bandwidth for all up ports
  loadAllPortBw(id, data.ports || []);
}

function renderDetailPorts(ports) {
  // Separate ports by category for display
  const physicalPorts = ports.filter(p =>
    p.port_type !== 'irb' &&
    p.port_type !== 'lag-member' &&
    !p.port_name.startsWith('irb')
  );
  const irbPorts  = ports.filter(p => p.port_type === 'irb' || p.port_name.startsWith('irb'));
  const lagMembers= ports.filter(p => p.port_type === 'lag-member');

  if (!physicalPorts.length && !irbPorts.length) {
    document.getElementById('dp-ports').innerHTML = '<div class="empty-state"><i data-lucide="ethernet" class="icon-lucide"></i><div>No ports discovered yet — poll the switch first</div></div>';
    return;
  }

  const typeLabel = (p) => {
    if (p.is_trunk == 1 && p.port_type === 'trunk') return '<span class="trunk-badge">TRUNK</span>';
    if (p.port_type === 'lag')         return '<span class="trunk-badge" style="background:rgba(163,113,247,.15);color:var(--nac-purple)">LAG</span>';
    if (p.port_type === 'lag-member')  return '<span style="color:var(--nac-muted);font-size:.72rem">LAG-mbr</span>';
    if (p.port_type === 'irb')         return '<span style="color:var(--nac-muted);font-size:.72rem">L3-GW</span>';
    return '<span style="color:var(--nac-green);font-size:.72rem">access</span>';
  };

  const portRow = (p) => {
    // Build a clear single-status pill instead of the confusing "○ up" pattern.
    // Old display: dot (link) + text (admin) → "○ up" meant "no cable, admin enabled" — confusing.
    // New display: one descriptive colored pill.
    let statusHtml;
    if (p.admin_status === 'down') {
      statusHtml = `<span style="font-size:.7rem;padding:1px 7px;border-radius:10px;
        background:rgba(139,148,158,.15);color:var(--nac-muted);border:1px solid var(--nac-border)">
        shutdown</span>`;
    } else if (p.link_status === 'up') {
      const isTrunk = p.is_trunk == 1;
      statusHtml = `<span style="font-size:.7rem;padding:1px 7px;border-radius:10px;
        background:rgba(63,185,80,.15);color:var(--nac-green);border:1px solid rgba(63,185,80,.3)">
        ● ${isTrunk ? 'trunk ↑' : 'connected'}</span>`;
    } else {
      statusHtml = `<span style="font-size:.7rem;padding:1px 7px;border-radius:10px;
        background:rgba(248,81,73,.12);color:var(--nac-red);border:1px solid rgba(248,81,73,.25)">
        ○ no link</span>`;
    }
    const desc = p.description || p.lldp_neighbor || '—';
    const bwId = `bw-${currentSwitchId}-${p.port_name.replace(/[^a-z0-9]/gi,'_')}`;
    const canBw = p.admin_status === 'up' && p.link_status === 'up';
    return `<tr>
      <td><span class="port-name">${escHtml(p.port_name)}</span></td>
      <td>${statusHtml}</td>
      <td>${typeLabel(p)}</td>
      <td><span class="vlan-badge">${escHtml(p.vlan_names||'—')}</span></td>
      <td style="text-align:center;">${p.live_macs||0}</td>
      <td style="font-size:.72rem;min-width:110px" id="${bwId}">
        ${canBw
          ? `<span style="color:var(--nac-muted);font-size:.68rem"><i data-lucide="loader" class="icon-lucide fa-spin"></i></span>`
          : `<span style="color:var(--nac-border)">—</span>`}
      </td>
      <td style="color:var(--nac-muted);font-size:.75rem;">${escHtml(desc)}</td>
    </tr>`;
  };

  let html = `<table class="port-table">
    <thead><tr>
      <th>Port</th><th>Status</th><th>Type</th><th>VLAN</th><th>MACs</th><th>Bandwidth</th><th>Neighbor / Description</th>
    </tr></thead><tbody>`;

  // Physical ports first (ge- and ae- ports)
  physicalPorts.sort((a,b) => naturalSort(a.port_name, b.port_name));
  physicalPorts.forEach(p => { html += portRow(p); });

  // IRB (L3 gateway) section
  if (irbPorts.length) {
    html += `<tr><td colspan="6" style="padding:8px 10px 4px;font-size:.7rem;color:var(--nac-muted);
      text-transform:uppercase;letter-spacing:.5px;border-top:2px solid var(--nac-border);">
      L3 Gateway Interfaces (IRB)</td></tr>`;
    irbPorts.sort((a,b) => naturalSort(a.port_name, b.port_name));
    irbPorts.forEach(p => { html += portRow(p); });
  }

  html += '</tbody></table>';
  document.getElementById('dp-ports').innerHTML = html;
}

function naturalSort(a, b) {
  return a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'});
}

function renderDetailMacs(macs) {
  if (!macs.length) {
    document.getElementById('dp-macs').innerHTML = '<div class="empty-state"><i data-lucide="fingerprint" class="icon-lucide"></i><div>No MACs tracked yet — poll the switch first</div></div>';
    return;
  }

  const access = macs.filter(m => !m.via_trunk || m.via_trunk == 0);
  const trunk  = macs.filter(m =>  m.via_trunk == 1);

  // Group access MACs by port for clarity
  const byPort = {};
  access.forEach(m => {
    const p = m.port_name || '?';
    if (!byPort[p]) byPort[p] = [];
    byPort[p].push(m);
  });
  const portKeys = Object.keys(byPort).sort((a,b) => naturalSort(a,b));

  const macRow = (m, showPort) => {
    const ip       = m.ip   ? `<span style="font-family:monospace;font-size:.78rem;color:var(--nac-text)">${escHtml(m.ip)}</span>` : `<span style="color:var(--nac-muted)">—</span>`;
    const hostname = m.hostname ? escHtml(m.hostname) : '<span style="color:var(--nac-muted)">—</span>';
    const vendor   = m.oui_vendor && m.oui_vendor !== 'Unknown' ? escHtml(m.oui_vendor) : (oui(m.mac) || '<span style="color:var(--nac-muted)">—</span>');
    const portCell = showPort
      ? `<td><span class="port-name" style="font-size:.75rem;">${escHtml(m.port_name)}</span></td>`
      : '<td></td>';
    return `<tr>
      <td><span class="mac-mono">${m.mac}</span></td>
      ${portCell}
      <td><span class="vlan-badge">${escHtml(m.vlan_name||'—')}</span></td>
      <td>${ip}</td>
      <td style="font-size:.78rem;">${hostname}</td>
      <td style="font-size:.72rem;color:var(--nac-muted);">${vendor}</td>
      <td>
        <button onclick="event.stopPropagation();traceMac('${m.mac}',this)"
          style="font-size:.65rem;padding:1px 6px;background:rgba(59,130,246,.12);
                 color:var(--nac-accent);border:1px solid rgba(59,130,246,.3);
                 border-radius:3px;cursor:pointer">🔍</button>
        <button onclick="event.stopPropagation();showMacHistory('${m.mac}')"
          style="font-size:.65rem;padding:1px 6px;background:rgba(163,113,247,.12);
                 color:var(--nac-purple);border:1px solid rgba(163,113,247,.3);
                 border-radius:3px;cursor:pointer;margin-left:3px">📋</button>
      </td>
    </tr>`;
  };

  const tableHeader = `<table class="port-table" style="width:100%">
    <thead><tr>
      <th>MAC</th><th>Port</th><th>VLAN</th><th>IP</th><th>Hostname / Label</th><th>Vendor</th><th></th>
    </tr></thead><tbody>`;

  let html = `<div style="margin-bottom:10px;font-size:.78rem;color:var(--nac-muted);display:flex;gap:16px;flex-wrap:wrap;">
    <span><strong style="color:var(--nac-text)">${macs.length}</strong> MACs total</span>
    ${access.length ? `<span style="color:var(--nac-green)">● <strong>${access.length}</strong> on access ports</span>` : ''}
    ${trunk.length  ? `<span style="color:var(--nac-orange)">◈ <strong>${trunk.length}</strong> via trunk / LAG</span>` : ''}
  </div>`;

  // ── ACCESS SECTION — grouped by port ──
  if (access.length) {
    html += `<div style="margin-bottom:6px;font-size:.72rem;color:var(--nac-green);
      text-transform:uppercase;letter-spacing:.5px;font-weight:700;padding:4px 0;">
      ● Direct Access Ports (${access.length} MACs across ${portKeys.length} port${portKeys.length!==1?'s':''})</div>`;

    portKeys.forEach(pname => {
      const portMacs = byPort[pname];
      const vlan = portMacs[0].vlan_name || '—';
      html += `<div style="margin-bottom:10px;">
        <div style="font-size:.75rem;font-weight:600;padding:5px 8px;
          background:rgba(34,197,94,.08);border-left:3px solid var(--nac-green);
          border-radius:0 4px 4px 0;margin-bottom:0;display:flex;align-items:center;gap:10px;">
          <span class="port-name">${escHtml(pname)}</span>
          <span class="vlan-badge">${escHtml(vlan)}</span>
          <span style="color:var(--nac-muted);font-weight:400">${portMacs.length} MAC${portMacs.length!==1?'s':''}</span>
        </div>
        ${tableHeader}`;
      portMacs.forEach(m => { html += macRow(m, false); });
      html += `</tbody></table></div>`;
    });
  }

  // ── TRUNK / LAG SECTION ──
  if (trunk.length) {
    html += `<div style="margin-top:${access.length?'14px':'0'};margin-bottom:6px;
      font-size:.72rem;color:var(--nac-orange);text-transform:uppercase;
      letter-spacing:.5px;font-weight:700;padding:4px 0;">
      ◈ Via Trunk / LAG — behind downstream switch (${trunk.length} MACs)</div>`;
    html += tableHeader;
    trunk.forEach(m => { html += macRow(m, true); });
    html += `</tbody></table>`;
  }

  document.getElementById('dp-macs').innerHTML = html;
}

// Minimal OUI lookup for MAC tab vendor enrichment
function oui(mac) {
  const prefix = mac.substring(0,8).toUpperCase();
  const map = {
    '00:0C:29':'VMware','00:50:56':'VMware vSphere','BC:24:11':'Proxmox/Linux',
    '04:D5:90':'Fortinet','84:39:8F':'Fortinet','80:AC:AC':'Juniper',
    '00:1D:09':'Dell','44:A8:42':'Dell','F8:DB:88':'Dell','E4:3D:1A':'Dell iDRAC',
    'B4:7A:F1':'HP','38:68:DD':'HP','28:80:23':'HP','2C:EA:7F':'HP',
    '14:18:77':'Lenovo','34:B3:54':'Lenovo','98:BE:94':'Lenovo',
    '00:31:46':'Supermicro','50:EB:1A':'Supermicro','94:40:C9':'Supermicro',
    '64:00:6A':'Cisco','40:5B:7F':'Cisco','48:4D:7E':'Cisco',
    'C8:1F:66':'Yealink','C8:1F:EA':'Yealink','6C:A8:49':'Yealink',
    '00:60:16':'EMC','00:90:FA':'HP-UX/Agilent','AE:B6:1D':'Random/VM',
  };
  return map[prefix] || '';
}

function renderDetailEvents(events) {
  const el = document.getElementById('dp-events');
  if (!events.length) {
    el.innerHTML = '<div class="empty-state"><i data-lucide="history" class="icon-lucide"></i><div>No events recorded yet</div></div>';
    return;
  }

  const icons = {
    mac_moved:'fa-arrows-alt', new_mac:'fa-plus-circle', vlan_change:'fa-tag',
    port_down:'fa-times-circle', port_up:'fa-check-circle', mac_lost:'fa-ghost',
    multi_mac:'fa-sitemap', poll_error:'fa-exclamation-triangle'
  };
  const colors = {
    mac_moved:'var(--nac-orange)', new_mac:'var(--nac-accent)', vlan_change:'var(--nac-yellow)',
    port_down:'var(--nac-red)', port_up:'var(--nac-green)', mac_lost:'var(--nac-muted)',
    multi_mac:'var(--nac-purple)', poll_error:'var(--nac-red)'
  };

  const renderContext = (e) => {
    const parts = [];
    if (e.mac)      parts.push(`<span class="mac-mono" style="font-size:.75rem">${escHtml(e.mac)}</span>`);
    if (e.old_port && e.new_port && e.old_port !== e.new_port)
      parts.push(`Port: <strong>${escHtml(e.old_port)}</strong> → <strong style="color:var(--nac-green)">${escHtml(e.new_port)}</strong>`);
    else if (e.new_port)
      parts.push(`Port: <strong>${escHtml(e.new_port)}</strong>`);
    else if (e.old_port)
      parts.push(`Port: <strong>${escHtml(e.old_port)}</strong>`);
    if (e.old_vlan && e.new_vlan && e.old_vlan !== e.new_vlan)
      parts.push(`VLAN: <span class="vlan-badge">${escHtml(e.old_vlan)}</span> → <span class="vlan-badge" style="background:rgba(34,197,94,.15)">${escHtml(e.new_vlan)}</span>`);
    else if (e.new_vlan)
      parts.push(`VLAN: <span class="vlan-badge">${escHtml(e.new_vlan)}</span>`);
    return parts.length ? `<div style="margin-top:4px;display:flex;gap:10px;flex-wrap:wrap;font-size:.74rem">${parts.join('<span style="color:var(--nac-border)">|</span>')}</div>` : '';
  };

  el.innerHTML = `<div style="padding:0 2px">` + events.map(e => {
    const color    = colors[e.event_type] || 'var(--nac-muted)';
    const icon     = icons[e.event_type]  || 'fa-bell';
    const isAlarm  = e.is_alarm == 1;
    const isAcked  = e.acknowledged == 1;
    return `
    <div class="alarm-item alarm-${e.event_type}" style="position:relative;padding:10px 12px 10px 14px;margin-bottom:6px">
      <div style="display:flex;align-items:flex-start;gap:8px">
        <i class="fas ${icon}" style="color:${color};margin-top:2px;flex-shrink:0"></i>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="font-weight:600;font-size:.82rem">${fmtEventType(e.event_type)}</span>
            ${isAlarm && !isAcked ? `<span style="font-size:.65rem;background:rgba(248,81,73,.15);color:var(--nac-red);padding:1px 6px;border-radius:10px;border:1px solid rgba(248,81,73,.3)">⚡ Alarm</span>` : ''}
            ${isAcked ? `<span style="font-size:.65rem;color:var(--nac-green)">✓ Acked${e.ack_by ? ' by ' + escHtml(e.ack_by) : ''}</span>` : ''}
            <span style="font-size:.7rem;color:var(--nac-muted);margin-left:auto">${timeAgo(e.created_at)}</span>
          </div>
          <div style="color:var(--nac-muted);font-size:.74rem;font-family:monospace;margin-top:3px">${escHtml(e.details||'')}</div>
          ${renderContext(e)}
        </div>
        ${isAlarm && !isAcked
          ? `<button onclick="ackEventInline(${e.id},this)" class="alarm-ack-btn" style="flex-shrink:0;margin-top:0">✓ Ack</button>`
          : ''}
      </div>
    </div>`;
  }).join('') + `</div>`;
}

async function ackEventInline(id, btn) {
  btn.textContent = '…';
  btn.disabled = true;
  await nacAjax('ack_event', { id, note: '' });
  btn.closest('.alarm-item').style.opacity = '0.5';
  btn.textContent = '✓';
  loadDashboard();
}

function showDTab(tab, el) {
  document.querySelectorAll('.dtab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  ['ports','macs','events'].forEach(t => {
    document.getElementById('dp-'+t).style.display = t === tab ? '' : 'none';
  });
}

// ── Poll actions ──
async function pollCurrentSwitch() {
  if (!currentSwitchId) return;
  const btn = document.getElementById('dp-poll-btn');
  btn.innerHTML = '<div class="spinner"></div> Polling…';
  btn.disabled = true;
  const r = await nacAjax('poll_switch', { id: currentSwitchId });
  btn.innerHTML = '<i data-lucide="refresh-ccw" class="icon-lucide -alt"></i> Poll Now';
  btn.disabled = false;
  if (r && r.ok) {
    loadDetailPanel(currentSwitchId);
    loadDashboard();
  } else {
    alert('Poll failed: ' + (r?.error || 'Unknown error'));
  }
}

async function pollAll() {
  const btn = event.target;
  btn.innerHTML = '<div class="spinner"></div> Polling…';
  btn.disabled = true;
  await nacAjax('poll_all');
  btn.innerHTML = '<i data-lucide="refresh-ccw" class="icon-lucide -alt"></i> Poll All';
  btn.disabled = false;
  loadDashboard();
}

// ── Connection type toggle (shows/hides enable password, SNMP fields, hints) ──
function nacToggleConnFields(prefix) {
  const ctId    = prefix === 'add' ? 'sw-conntype' : 'edit-sw-conntype';
  const ctVal   = document.getElementById(ctId)?.value || 'ssh';
  const isTelnet = ctVal === 'telnet';
  const isSnmp   = ctVal === 'snmp';

  // Enable password row — only for Telnet
  const epRow = document.getElementById(prefix === 'add' ? 'sw-enablepass-row' : 'edit-sw-enablepass-row');
  if (epRow) epRow.style.display = isTelnet ? '' : 'none';

  // SNMP fields block
  const snmpBlock = document.getElementById(prefix === 'add' ? 'sw-snmp-fields' : 'edit-sw-snmp-fields');
  if (snmpBlock) snmpBlock.style.display = isSnmp ? '' : 'none';

  if (prefix === 'add') {
    const portLabel  = document.getElementById('sw-port-label');
    const portInput  = document.getElementById('sw-sshport');
    const sshHint    = document.getElementById('sw-ssh-hint');
    const telnetHint = document.getElementById('sw-telnet-hint');

    // Show/hide SSH port row (not needed for SNMP — SNMP has its own port field)
    const portRow = portInput?.closest('.form-row');
    if (portRow) portRow.style.display = isSnmp ? 'none' : '';

    if (portLabel)  portLabel.textContent = isTelnet ? 'Telnet Port' : 'SSH Port';
    if (portInput && portInput.value === '22' && isTelnet)  portInput.value = '23';
    if (portInput && portInput.value === '23' && !isTelnet && !isSnmp) portInput.value = '22';
    if (sshHint)    sshHint.style.display    = isSnmp ? 'none' : (isTelnet ? 'none' : '');
    if (telnetHint) telnetHint.style.display = isTelnet ? '' : 'none';

    // SSH credential rows — hide when SNMP
    const userRow = document.getElementById('sw-user')?.closest('.form-row');
    const passRow = document.getElementById('sw-pass')?.closest('.form-row');
    if (userRow) userRow.style.display = isSnmp ? 'none' : '';
    if (passRow) passRow.style.display = isSnmp ? 'none' : '';
  }

  if (prefix === 'edit') {
    // SSH credential rows — hide when SNMP
    const userRow = document.getElementById('edit-sw-user')?.closest('.form-row');
    const passRow = document.getElementById('edit-sw-pass')?.closest('.form-row');
    if (userRow) userRow.style.display = isSnmp ? 'none' : '';
    if (passRow) passRow.style.display = isSnmp ? 'none' : '';
  }
}

// Auto-select connection type when platform changes
// JunOS / ProCurve / Aruba  → SNMP (standard MIBs work great)
// Cisco IOS/IOS-XE          → SSH  (SNMP per-VLAN trick works but SSH is simpler)
// Cisco IOS Legacy/Telnet   → Telnet (old switches, no SSH)
document.addEventListener('DOMContentLoaded', () => {
  ['sw-platform','edit-sw-platform'].forEach(selId => {
    const sel = document.getElementById(selId);
    if (!sel) return;
    sel.addEventListener('change', () => {
      const prefix = selId.startsWith('edit') ? 'edit' : 'add';
      const ctId   = prefix === 'add' ? 'sw-conntype' : 'edit-sw-conntype';
      const ct     = document.getElementById(ctId);
      if (!ct) return;
      if (sel.value === 'ios-legacy' || sel.value === 'ios-telnet') {
        ct.value = 'telnet';
      } else if (['ios','ios-xe'].includes(sel.value)) {
        ct.value = 'ssh';   // Cisco SSH — SNMP works but needs per-VLAN community trick
      } else if (['junos','junos-new','procurve','aruba-os','aruba'].includes(sel.value)) {
        ct.value = 'snmp';  // These all support standard MIBs perfectly
      }
      nacToggleConnFields(prefix);
    });
  });
});

// ── Add switch ──
function openAddSwitch() {
  // Reset to SNMP default on open
  document.getElementById('sw-conntype').value = 'snmp';
  // Clear form fields
  document.getElementById('sw-hostname').value  = '';
  document.getElementById('sw-ip').value        = '';
  document.getElementById('sw-model').value     = '';
  document.getElementById('sw-user').value      = '';
  document.getElementById('sw-pass').value      = '';
  document.getElementById('sw-location').value  = '';
  document.getElementById('sw-notes').value     = '';
  const comm = document.getElementById('sw-snmp-community');
  if (comm && !comm.value) comm.value = 'public';
  const snmpPort = document.getElementById('sw-snmp-port');
  if (snmpPort) snmpPort.value = '161';
  const testResult = document.getElementById('sw-snmp-test-result');
  if (testResult) testResult.innerHTML = '';
  nacToggleConnFields('add');
  document.getElementById('addSwitchModal').classList.add('show');
}

// ── Edit switch ──
async function openEditSwitch(id) {
  // Fetch current switch data from list
  const switches = await nacAjax('list_switches');
  const sw = (switches || []).find(s => parseInt(s.id) === parseInt(id));
  if (!sw) { alert('Switch not found'); return; }

  document.getElementById('edit-sw-id').value        = sw.id;
  document.getElementById('edit-sw-ip-hidden').value = sw.ip;
  document.getElementById('edit-sw-hostname').value  = sw.hostname || '';
  document.getElementById('edit-sw-ip').value        = sw.ip;
  document.getElementById('edit-sw-model').value     = sw.model || '';
  document.getElementById('edit-sw-user').value      = sw.ssh_user || '';
  document.getElementById('edit-sw-pass').value      = '';
  document.getElementById('edit-sw-enablepass').value= '';
  document.getElementById('edit-sw-location').value  = sw.location || '';
  document.getElementById('edit-sw-notes').value     = sw.notes || '';
  document.getElementById('edit-sw-enabled').value   = sw.enabled ?? 1;

  // Group
  const grpSel = document.getElementById('edit-sw-group');
  if (grpSel) grpSel.value = sw.group_id || '0';

  // Connection type
  const ct = document.getElementById('edit-sw-conntype');
  ct.value = sw.connection_type || 'ssh';

  // SNMP fields
  const snmpComm = document.getElementById('edit-sw-snmp-community');
  const snmpPort = document.getElementById('edit-sw-snmp-port');
  if (snmpComm) snmpComm.value = sw.snmp_community || '';
  if (snmpPort) snmpPort.value = sw.snmp_port      || 161;

  nacToggleConnFields('edit');

  // Select correct platform
  const plSel = document.getElementById('edit-sw-platform');
  for (let opt of plSel.options) {
    if (opt.value === sw.platform_key) { opt.selected = true; break; }
  }

  document.getElementById('editSwitchModal').classList.add('show');
}

async function submitEditSwitch() {
  const id          = document.getElementById('edit-sw-id').value;
  const ip          = document.getElementById('edit-sw-ip-hidden').value;
  const hostname    = document.getElementById('edit-sw-hostname').value.trim();
  const model       = document.getElementById('edit-sw-model').value.trim();
  const user        = document.getElementById('edit-sw-user').value.trim();
  const pass        = document.getElementById('edit-sw-pass').value;
  const enable_pass = document.getElementById('edit-sw-enablepass').value;
  const location    = document.getElementById('edit-sw-location').value.trim();
  const notes       = document.getElementById('edit-sw-notes').value.trim();
  const platform    = document.getElementById('edit-sw-platform').value;
  const enabled     = document.getElementById('edit-sw-enabled').value;
  const conn_type   = document.getElementById('edit-sw-conntype').value;

  if (!hostname) { alert('Hostname is required'); return; }
  if (conn_type !== 'snmp' && !user) { alert('Username is required for SSH/Telnet'); return; }

  const btn = document.getElementById('editSwitchBtn');
  btn.innerHTML = '<div class="spinner"></div> Saving…';
  btn.disabled = true;

  const payload = { id, ip, hostname, model, ssh_user: user, location, notes,
                    platform_key: platform, enabled, connection_type: conn_type,
                    group_id: document.getElementById('edit-sw-group')?.value || 0 };
  if (pass)        payload.ssh_pass    = pass;
  if (enable_pass) payload.enable_pass = enable_pass;
  if (conn_type === 'snmp') {
    payload.snmp_community = document.getElementById('edit-sw-snmp-community').value.trim();
    payload.snmp_version   = 2;
    payload.snmp_port      = document.getElementById('edit-sw-snmp-port').value || 161;
  }

  const r = await nacAjax('edit_switch', payload);
  btn.innerHTML = '<i data-lucide="save" class="icon-lucide"></i> Save Changes';
  btn.disabled = false;

  if (r && r.ok) {
    closeModal('editSwitchModal');
    loadDashboard();
  } else {
    alert('Error: ' + (r?.error || 'Unknown error'));
  }
}

// ── Delete switch ──
let pendingDeleteId = null;

function confirmDeleteSwitch(id, hostname) {
  pendingDeleteId = id;
  document.getElementById('del-sw-name').textContent = hostname;
  // Get IP from card
  const switches_cached = window._cachedSwitches || [];
  const sw = switches_cached.find(s => parseInt(s.id) === parseInt(id));
  document.getElementById('del-sw-ip').textContent = sw ? sw.ip : '';
  document.getElementById('deleteSwitchModal').classList.add('show');
}

async function submitDeleteSwitch() {
  if (!pendingDeleteId) return;
  const btn = document.getElementById('deleteSwitchBtn');
  btn.innerHTML = '<div class="spinner"></div> Deleting…';
  btn.disabled = true;

  const r = await nacAjax('delete_switch', { id: pendingDeleteId });
  btn.innerHTML = '<i data-lucide="trash" class="icon-lucide -alt"></i> Delete Permanently';
  btn.disabled = false;

  if (r && r.ok) {
    pendingDeleteId = null;
    closeModal('deleteSwitchModal');
    loadDashboard();
  } else {
    alert('Error: ' + (r?.error || 'Unknown error'));
  }
}

async function submitAddSwitch() {
  const hostname    = document.getElementById('sw-hostname').value.trim();
  const ip          = document.getElementById('sw-ip').value.trim();
  const model       = document.getElementById('sw-model').value.trim();
  const user        = document.getElementById('sw-user').value.trim();
  const pass        = document.getElementById('sw-pass').value;
  const sshport     = document.getElementById('sw-sshport').value;
  const location    = document.getElementById('sw-location').value.trim();
  const notes       = document.getElementById('sw-notes').value.trim();
  const platform    = document.getElementById('sw-platform').value;
  const conn_type   = document.getElementById('sw-conntype').value;
  const enable_pass = document.getElementById('sw-enablepass').value;

  // Validation depends on connection type
  if (!ip) { alert('IP address is required'); return; }
  if (conn_type === 'snmp') {
    const snmp_community = document.getElementById('sw-snmp-community').value.trim();
    if (!snmp_community) { alert('SNMP community string is required'); return; }
  } else {
    if (!user || !pass) { alert('Username and password are required for SSH/Telnet'); return; }
  }

  const btn = document.getElementById('addSwitchBtn');
  btn.innerHTML = '<div class="spinner"></div> Adding…';
  btn.disabled = true;

  const payload = {
    hostname, ip, model, ssh_user: user, ssh_pass: pass,
    ssh_port: sshport, location, notes, platform_key: platform,
    connection_type: conn_type, enable_pass,
    group_id: document.getElementById('sw-group')?.value || 0
  };
  if (conn_type === 'snmp') {
    payload.snmp_community = document.getElementById('sw-snmp-community').value.trim();
    payload.snmp_version   = 2;
    payload.snmp_port      = document.getElementById('sw-snmp-port').value || 161;
  }
  const r = await nacAjax('add_switch', payload);

  btn.innerHTML = '<i data-lucide="plus" class="icon-lucide"></i> Add Switch';
  btn.disabled = false;

  if (r && r.ok) {
    closeModal('addSwitchModal');
    loadDashboard();
    // Auto-poll the new switch
    await nacAjax('poll_switch', { id: r.id });
    loadDashboard();
  } else {
    alert('Error: ' + (r?.error || 'Unknown error'));
  }
}

// ── Search ──
function setupSearch() {
  const inp = document.getElementById('globalSearch');
  const res = document.getElementById('searchResults');
  let searchTimer = null;

  inp.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = inp.value.trim();
    if (q.length < 2) { res.classList.remove('show'); return; }
    searchTimer = setTimeout(() => doSearch(q), 300);
  });

  inp.addEventListener('keydown', e => {
    if (e.key === 'Escape') { res.classList.remove('show'); inp.value = ''; }
  });

  document.addEventListener('click', e => {
    if (!inp.contains(e.target) && !res.contains(e.target)) res.classList.remove('show');
  });
}

async function doSearch(q) {
  const res = document.getElementById('searchResults');
  res.innerHTML = '<div class="sr-no-result"><div class="spinner"></div></div>';
  res.classList.add('show');

  const data = await nacAjax('search', { q });
  if (!data || !data.length) {
    res.innerHTML = `<div class="sr-no-result"><i class="fas fa-search" style="margin-right:5px"></i>No results for "<strong>${escHtml(q)}</strong>"</div>`;
    return;
  }

  res.innerHTML = data.map(r => {
    const ip       = r.ip || '—';
    const hostname = r.hostname || 'Unknown device';
    const sw       = r.nac_switch_name ? `${r.nac_switch_name}` : 'Not tracked in NAC';
    const port     = r.nac_port || '—';
    const vlan     = r.nac_vlan || '—';
    const vendor   = r.vendor || r.oui_vendor || '';
    const status   = r.ipam_status || '';
    const statusColor = status === 'active' ? 'var(--nac-green)' : 'var(--nac-muted)';
    const isTrunk  = r.via_trunk == 1;

    const trunkWarning = isTrunk
      ? `<span style="color:var(--nac-yellow);font-size:.7rem;margin-left:6px">
           <i data-lucide="code-branch" class="icon-lucide"></i> via trunk
         </span>
         <button onclick="event.stopPropagation();traceMac('${escHtml(r.mac||'')}',this)"
           style="font-size:.68rem;padding:1px 7px;margin-left:6px;background:rgba(59,130,246,.15);
                  color:var(--nac-accent);border:1px solid var(--nac-accent);border-radius:4px;cursor:pointer">
           🔍 Trace
         </button>`
      : '';

    return `<div class="sr-item" onclick="handleSearchClick('${escHtml(r.mac||'')}','${escHtml(r.nac_switch_name||'')}',${JSON.stringify(r).replace(/"/g,'&quot;')})">
      <div class="sr-main">
        <span style="color:${statusColor}">●</span>
        <strong style="margin:0 4px">${escHtml(ip)}</strong>
        <span style="color:var(--nac-muted)">${escHtml(hostname)}</span>
        ${vendor ? `<span class="sr-badge" style="margin-left:6px">${escHtml(vendor)}</span>` : ''}
      </div>
      <div class="sr-sub">
        <span class="mac-mono" style="color:var(--nac-accent)">${escHtml(r.mac||'')}</span>
        ${port !== '—' ? `&nbsp;→&nbsp;<span class="sr-port">${escHtml(sw)}</span>
        <strong style="margin:0 3px;color:var(--nac-text)">${escHtml(port)}</strong>
        <span class="vlan-badge" style="margin-left:4px">${escHtml(vlan)}</span>` : ''}
        ${trunkWarning}
        ${r.ipam_last_seen ? `<span style="margin-left:8px;color:var(--nac-muted)">${timeAgo(r.ipam_last_seen)}</span>` : ''}
      </div>
    </div>`;
  }).join('');
}

function handleSearchClick(mac, switchName, resultData) {
  document.getElementById('searchResults').classList.remove('show');
  document.getElementById('globalSearch').value = '';
  openMacDetailModal(mac, resultData);
}

/* ── MAC Detail modal (opened from search results) ── */
async function openMacDetailModal(mac, r) {
  const existing = document.getElementById('macDetailModal');
  if (existing) existing.remove();

  const statusColor = (r.ipam_status === 'active') ? 'var(--nac-green)' : 'var(--nac-muted)';
  const isTrunk     = r.via_trunk == 1;

  const modal = document.createElement('div');
  modal.id = 'macDetailModal';
  modal.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;
    display:flex;align-items:center;justify-content:center;padding:16px`;

  modal.innerHTML = `
  <div style="background:var(--nac-surface);border:1px solid var(--nac-border);border-radius:14px;
       width:640px;max-width:98vw;max-height:90vh;overflow-y:auto;
       box-shadow:0 24px 64px rgba(0,0,0,.5);display:flex;flex-direction:column">

    <!-- Header -->
    <div style="padding:20px 22px 14px;border-bottom:1px solid var(--nac-border);
         display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0">
      <div>
        <div style="font-size:.72rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">
          Device Detail
        </div>
        <div style="font-family:monospace;font-size:1.05rem;font-weight:700;color:var(--nac-accent)">${escHtml(mac)}</div>
        ${r.hostname && r.hostname !== 'Unknown device'
          ? `<div style="font-size:.85rem;color:var(--nac-text);margin-top:3px">${escHtml(r.hostname)}</div>` : ''}
      </div>
      <button onclick="document.getElementById('macDetailModal').remove()"
        style="background:none;border:none;color:var(--nac-muted);font-size:1.4rem;cursor:pointer;line-height:1;flex-shrink:0">✕</button>
    </div>

    <!-- Info grid -->
    <div style="padding:16px 22px;display:grid;grid-template-columns:1fr 1fr;gap:10px;border-bottom:1px solid var(--nac-border)">
      <div style="background:var(--nac-bg);border-radius:8px;padding:11px 14px">
        <div style="font-size:.68rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">IP Address</div>
        <div style="font-family:monospace;font-size:.95rem;font-weight:600">${escHtml(r.ip || '—')}</div>
      </div>
      <div style="background:var(--nac-bg);border-radius:8px;padding:11px 14px">
        <div style="font-size:.68rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">Status</div>
        <div style="font-size:.9rem;font-weight:600;color:${statusColor}">${r.ipam_status || '—'}</div>
      </div>
      <div style="background:var(--nac-bg);border-radius:8px;padding:11px 14px">
        <div style="font-size:.68rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">Switch</div>
        <div style="font-size:.85rem;font-weight:600">${escHtml(r.nac_switch_name || 'Not in NAC')}</div>
        ${r.switch_ip ? `<div style="font-size:.72rem;color:var(--nac-muted);font-family:monospace">${escHtml(r.switch_ip)}</div>` : ''}
      </div>
      <div style="background:var(--nac-bg);border-radius:8px;padding:11px 14px">
        <div style="font-size:.68rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">Port / VLAN</div>
        <div style="font-size:.85rem">
          ${r.nac_port ? `<span class="port-name">${escHtml(r.nac_port)}</span>` : '—'}
          ${r.nac_vlan ? `<span class="vlan-badge" style="margin-left:6px">${escHtml(r.nac_vlan)}</span>` : ''}
          ${isTrunk ? `<span style="display:block;margin-top:3px;font-size:.7rem;color:var(--nac-yellow)"><i data-lucide="code-branch" class="icon-lucide"></i> via trunk</span>` : ''}
        </div>
      </div>
      ${r.vendor || r.oui_vendor ? `
      <div style="background:var(--nac-bg);border-radius:8px;padding:11px 14px">
        <div style="font-size:.68rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">Vendor (OUI)</div>
        <div style="font-size:.85rem">${escHtml(r.vendor || r.oui_vendor)}</div>
      </div>` : ''}
      ${r.ipam_last_seen ? `
      <div style="background:var(--nac-bg);border-radius:8px;padding:11px 14px">
        <div style="font-size:.68rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">Last Seen</div>
        <div style="font-size:.85rem">${timeAgo(r.ipam_last_seen)}</div>
        <div style="font-size:.68rem;color:var(--nac-muted)">${escHtml(r.ipam_last_seen)}</div>
      </div>` : ''}
    </div>

    <!-- Tabs -->
    <div style="display:flex;border-bottom:1px solid var(--nac-border);flex-shrink:0">
      <button class="md-tab active" data-tab="history"
        style="padding:10px 18px;background:none;border:none;border-bottom:2px solid var(--nac-accent);
               color:var(--nac-accent);font-size:.82rem;font-weight:600;cursor:pointer">
        <i data-lucide="history" class="icon-lucide"></i> Event History
      </button>
      <button class="md-tab" data-tab="ipam"
        style="padding:10px 18px;background:none;border:none;border-bottom:2px solid transparent;
               color:var(--nac-muted);font-size:.82rem;cursor:pointer">
        <i data-lucide="database" class="icon-lucide"></i> IPAM Alerts
      </button>
    </div>

    <!-- Tab bodies -->
    <div style="padding:14px 22px;flex:1">
      <div id="mdt-history"><div style="color:var(--nac-muted);font-size:.82rem">
        <span class="spinner" style="display:inline-block;margin-right:6px"></span>Loading…</div></div>
      <div id="mdt-ipam" style="display:none"><div style="color:var(--nac-muted);font-size:.82rem">
        <span class="spinner" style="display:inline-block;margin-right:6px"></span>Loading…</div></div>
    </div>

    <!-- Footer actions -->
    <div style="padding:12px 22px 18px;border-top:1px solid var(--nac-border);display:flex;gap:8px;flex-wrap:wrap;flex-shrink:0">
      ${isTrunk ? `
      <button onclick="traceMac('${escHtml(mac)}',this);document.getElementById('macDetailModal').remove()"
        style="font-size:.78rem;padding:6px 14px;background:rgba(59,130,246,.15);color:var(--nac-accent);
               border:1px solid rgba(59,130,246,.3);border-radius:6px;cursor:pointer">
        🔍 Trace MAC
      </button>` : ''}
      ${r.nac_switch_name ? `
      <button onclick="openSwitchByName('${escHtml(r.nac_switch_name)}');document.getElementById('macDetailModal').remove()"
        style="font-size:.78rem;padding:6px 14px;background:rgba(34,197,94,.15);color:var(--nac-green);
               border:1px solid rgba(34,197,94,.3);border-radius:6px;cursor:pointer">
        <i data-lucide="server" class="icon-lucide"></i> Go to Switch
      </button>` : ''}
      <button onclick="document.getElementById('macDetailModal').remove()"
        style="font-size:.78rem;padding:6px 14px;background:rgba(139,148,158,.12);color:var(--nac-muted);
               border:1px solid var(--nac-border);border-radius:6px;cursor:pointer;margin-left:auto">
        Close
      </button>
    </div>
  </div>`;

  // Tab switching
  modal.querySelectorAll('.md-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      modal.querySelectorAll('.md-tab').forEach(b => {
        b.style.borderBottomColor = 'transparent';
        b.style.color = 'var(--nac-muted)';
      });
      btn.style.borderBottomColor = 'var(--nac-accent)';
      btn.style.color = 'var(--nac-accent)';
      const t = btn.dataset.tab;
      ['history','ipam'].forEach(id => {
        document.getElementById('mdt-'+id).style.display = (id === t) ? '' : 'none';
      });
    });
  });

  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);

  // ── Load MAC event history ──
  nacAjax('mac_history', { mac }).then(events => {
    const el = document.getElementById('mdt-history');
    if (!el) return;
    if (!events || !events.length) {
      el.innerHTML = `<div style="color:var(--nac-muted);padding:10px 0;font-size:.82rem">No NAC events recorded for this MAC yet. Try polling the switch first.</div>`;
      return;
    }
    const typeLabels = { mac_moved:'Moved', new_mac:'First Seen', vlan_change:'VLAN Change',
      port_down:'Port Down', port_up:'Port Up', mac_lost:'Lost', multi_mac:'Multi-MAC', poll_error:'Poll Error' };
    const typeColors = { mac_moved:'var(--nac-orange)', new_mac:'var(--nac-accent)', vlan_change:'var(--nac-yellow)',
      port_down:'var(--nac-red)', port_up:'var(--nac-green)', mac_lost:'var(--nac-muted)', multi_mac:'var(--nac-purple)' };
    el.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:.78rem">
      <thead><tr style="color:var(--nac-muted);font-size:.68rem;text-transform:uppercase">
        <th style="padding:4px 8px 6px 0;text-align:left;white-space:nowrap">Time</th>
        <th style="padding:4px 8px 6px;text-align:left">Event</th>
        <th style="padding:4px 8px 6px;text-align:left">Switch</th>
        <th style="padding:4px 8px 6px;text-align:left">Port</th>
        <th style="padding:4px 8px 6px;text-align:left">VLAN</th>
        <th style="padding:4px 0 6px;text-align:left">Detail</th>
      </tr></thead><tbody>` +
      events.map(ev => {
        const color    = typeColors[ev.event_type] || 'var(--nac-muted)';
        const portInfo = ev.new_port || ev.old_port || '—';
        const vlanInfo = ev.new_vlan || ev.old_vlan || '—';
        const isAlarm  = ev.is_alarm == 1 && !ev.acknowledged;
        return `<tr style="border-top:1px solid var(--nac-border)">
          <td style="padding:6px 8px 6px 0;color:var(--nac-muted);white-space:nowrap">${timeAgo(ev.created_at)}</td>
          <td style="padding:6px 8px;white-space:nowrap">
            <span style="color:${color};font-weight:600">${typeLabels[ev.event_type] || ev.event_type}</span>
            ${isAlarm ? `<span style="font-size:.65rem;background:rgba(248,81,73,.15);color:var(--nac-red);
              padding:1px 5px;border-radius:8px;margin-left:4px;border:1px solid rgba(248,81,73,.3)">⚡</span>` : ''}
          </td>
          <td style="padding:6px 8px">${escHtml(ev.switch_name || '—')}</td>
          <td style="padding:6px 8px;font-family:monospace">${escHtml(portInfo)}</td>
          <td style="padding:6px 8px">
            ${vlanInfo !== '—' ? `<span class="vlan-badge">${escHtml(vlanInfo)}</span>` : '—'}
          </td>
          <td style="padding:6px 0;color:var(--nac-muted);max-width:160px;overflow:hidden;
               text-overflow:ellipsis;white-space:nowrap" title="${escHtml(ev.details||'')}">${escHtml(ev.details||'')}</td>
        </tr>
        ${ev.acknowledged ? `<tr><td colspan="6" style="padding:2px 0 6px;font-size:.67rem;color:var(--nac-green)">
          ✓ Acked by ${escHtml(ev.ack_by||'?')}${ev.ack_note?' — '+escHtml(ev.ack_note):''}</td></tr>` : ''}`;
      }).join('') + `</tbody></table>`;
  });

  // ── Load IPAM alerts ──
  nacAjax('mac_ipam_alerts', { mac }).then(data => {
    const el = document.getElementById('mdt-ipam');
    if (!el) return;
    if (!data || data.error || !data.alerts || !data.alerts.length) {
      let extra = '';
      if (data && data.ipam) {
        const d = data.ipam;
        extra = `<div style="background:var(--nac-bg);border-radius:8px;padding:10px 13px;margin-bottom:10px;font-size:.8rem">
          <div style="color:var(--nac-muted);font-size:.68rem;text-transform:uppercase;margin-bottom:5px">IPAM Record</div>
          <div>IP: <strong>${escHtml(d.ip||'—')}</strong></div>
          ${d.assigned_to ? `<div>Assigned: ${escHtml(d.assigned_to)}</div>` : ''}
          ${d.vlan ? `<div>VLAN: <span class="vlan-badge">${escHtml(d.vlan)}</span></div>` : ''}
          <div>First seen: ${escHtml(d.first_seen||'—')} &nbsp;|&nbsp; Last seen: ${timeAgo(d.last_seen)}</div>
        </div>`;
      }
      el.innerHTML = extra + `<div style="color:var(--nac-muted);font-size:.82rem">No IPAM alerts for this MAC/IP.</div>`;
      return;
    }
    const alertColors = { spoofing:'var(--nac-red)', mac_change:'var(--nac-orange)', offline:'var(--nac-muted)',
      online:'var(--nac-green)', new:'var(--nac-accent)', info:'var(--nac-muted)' };
    let html = '';
    if (data.ipam) {
      const d = data.ipam;
      html += `<div style="background:var(--nac-bg);border-radius:8px;padding:10px 13px;margin-bottom:12px;font-size:.8rem">
        <div style="color:var(--nac-muted);font-size:.68rem;text-transform:uppercase;margin-bottom:5px">IPAM Record</div>
        <div>IP: <strong>${escHtml(d.ip||'—')}</strong> &nbsp;
        Status: <span style="color:${d.status==='active'?'var(--nac-green)':'var(--nac-muted)'}">${escHtml(d.status||'—')}</span></div>
        ${d.assigned_to ? `<div>Assigned to: ${escHtml(d.assigned_to)}</div>` : ''}
        ${d.vlan ? `<div>VLAN: <span class="vlan-badge">${escHtml(d.vlan)}</span></div>` : ''}
        <div>First seen: ${escHtml(d.first_seen||'—')} &nbsp;|&nbsp; Last seen: ${timeAgo(d.last_seen)}</div>
      </div>`;
    }
    html += `<div style="font-size:.68rem;color:var(--nac-muted);text-transform:uppercase;margin-bottom:6px;font-weight:600">Alert Log (${data.alerts.length})</div>`;
    html += `<table style="width:100%;border-collapse:collapse;font-size:.78rem">
      <thead><tr style="color:var(--nac-muted);font-size:.68rem;text-transform:uppercase">
        <th style="padding:4px 8px 6px 0;text-align:left">Time</th>
        <th style="padding:4px 8px 6px;text-align:left">Type</th>
        <th style="padding:4px 8px 6px;text-align:left">Note</th>
        <th style="padding:4px 0 6px;text-align:left">Status</th>
      </tr></thead><tbody>` +
      data.alerts.map(a => {
        const color = alertColors[a.alert_type] || 'var(--nac-muted)';
        return `<tr style="border-top:1px solid var(--nac-border)">
          <td style="padding:6px 8px 6px 0;color:var(--nac-muted);white-space:nowrap">${timeAgo(a.changed_at)}</td>
          <td style="padding:6px 8px;white-space:nowrap">
            <span style="color:${color};font-weight:600">${escHtml(a.alert_type||'info')}</span>
          </td>
          <td style="padding:6px 8px;color:var(--nac-muted);max-width:200px;overflow:hidden;
               text-overflow:ellipsis;white-space:nowrap" title="${escHtml(a.note||'')}">${escHtml(a.note||'—')}</td>
          <td style="padding:6px 0">
            ${a.acknowledged
              ? `<span style="color:var(--nac-green);font-size:.7rem">✓ Acked${a.acknowledged_by?' by '+escHtml(a.acknowledged_by):''}</span>`
              : `<span style="color:var(--nac-orange);font-size:.7rem">⚡ Open</span>`}
          </td>
        </tr>`;
      }).join('') + `</tbody></table>`;
    el.innerHTML = html;
  });
}

function openSwitchByName(name) {
  // Find switch in cached list and open detail panel
  const sw = (window._cachedSwitches || []).find(s => s.hostname === name);
  if (sw) loadDetailPanel(sw.id);
}

// ── MAC Trace ──
async function traceMac(mac, btn) {
  btn.textContent = '...';
  btn.disabled = true;
  const data = await nacAjax('mac_trace', { mac });
  btn.textContent = '🔍 Trace';
  btn.disabled = false;

  if (!data || !data.chain || !data.chain.length) {
    showTraceModal(mac, []);
    return;
  }
  showTraceModal(mac, data.chain);
}

function showTraceModal(mac, chain) {
  const existing = document.getElementById('traceModal');
  if (existing) existing.remove();

  const accessHops = chain.filter(c => c.is_access);
  const trunkHops  = chain.filter(c => c.is_trunk);

  let hopsHtml = '';
  if (!chain.length) {
    hopsHtml = `<div style="color:var(--nac-muted);padding:16px 0">No trace data found for this MAC.</div>`;
  } else {
    // Show trunk hops first, then access hops with a highlight
    const allHops = [...trunkHops, ...accessHops];
    hopsHtml = allHops.map((h, i) => {
      const isLast   = i === allHops.length - 1;
      const isAccess = h.is_access;
      const icon     = isAccess ? '🖥️' : '🔀';
      const label    = isAccess
        ? `<span style="color:var(--nac-green);font-weight:600">ACCESS PORT</span>`
        : `<span style="color:var(--nac-yellow)">trunk</span>`;
      const traced   = h.traced
        ? `<span style="font-size:.68rem;color:var(--nac-accent);margin-left:6px">(found via topology walk)</span>`
        : '';
      return `
        <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;
             ${isLast ? '' : 'border-bottom:1px solid var(--nac-border)'}">
          <div style="font-size:1.2rem;margin-top:2px">${icon}</div>
          <div style="flex:1">
            <div style="font-weight:600">${escHtml(h.switch_name)} <span style="color:var(--nac-muted);font-size:.8rem">${escHtml(h.switch_ip)}</span></div>
            <div style="margin-top:3px">
              Port <strong style="color:var(--nac-text)">${escHtml(h.port)}</strong>
              &nbsp;${label}
              <span class="vlan-badge" style="margin-left:6px">${escHtml(h.vlan||'—')}</span>
              ${traced}
            </div>
          </div>
          ${isAccess ? `<div style="font-size:1.4rem">✅</div>` : ''}
        </div>`;
    }).join('<div style="text-align:center;color:var(--nac-muted);font-size:.8rem;padding:2px 0">↓</div>');
  }

  const summary = accessHops.length
    ? `<div style="margin-top:12px;padding:10px 14px;background:rgba(34,197,94,.08);
           border:1px solid rgba(34,197,94,.25);border-radius:8px;font-size:.85rem">
         <strong>Access port found:</strong>
         ${escHtml(accessHops[0].switch_name)} → Port <strong>${escHtml(accessHops[0].port)}</strong>
         <span class="vlan-badge" style="margin-left:6px">${escHtml(accessHops[0].vlan||'—')}</span>
       </div>`
    : `<div style="margin-top:12px;padding:10px 14px;background:rgba(234,179,8,.08);
           border:1px solid rgba(234,179,8,.25);border-radius:8px;font-size:.85rem">
         <strong>⚠ Could not resolve to an access port.</strong>
         The MAC may be on a switch not yet added to NAC, or LLDP topology is incomplete.
       </div>`;

  const modal = document.createElement('div');
  modal.id = 'traceModal';
  modal.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;
    display:flex;align-items:center;justify-content:center`;
  modal.innerHTML = `
    <div style="background:var(--nac-card);border:1px solid var(--nac-border);border-radius:12px;
         width:480px;max-width:95vw;max-height:80vh;overflow-y:auto;padding:22px 24px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <div>
          <div style="font-weight:700;font-size:1rem">🔍 MAC Trace</div>
          <div class="mac-mono" style="color:var(--nac-accent);font-size:.85rem;margin-top:2px">${escHtml(mac)}</div>
        </div>
        <button onclick="document.getElementById('traceModal').remove()"
          style="background:none;border:none;color:var(--nac-muted);font-size:1.3rem;cursor:pointer">✕</button>
      </div>
      <div>${hopsHtml}</div>
      ${summary}
    </div>`;
  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);
}

// ── Ack alarm ──
async function ackAlarm(id) {
  await nacAjax('ack_event', { id, note: '' });
  document.getElementById('alarm-'+id)?.remove();
  loadDashboard();
}

// ── Modals ──
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

// ── Utilities ──
async function nacAjax(action, data = {}) {
  try {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    const r = await fetch('/modules/nac/nac_ajax.php', { method: 'POST', body: fd });
    return await r.json();
  } catch(e) { console.error('nacAjax error:', e); return null; }
}

function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val ?? '—';
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function fmtEventType(t) {
  const map = { mac_moved:'MAC Moved', new_mac:'New MAC', vlan_change:'VLAN Change',
    port_down:'Port Down', port_up:'Port Up', mac_lost:'MAC Lost',
    trunk_detected:'Trunk Detected', poll_error:'Poll Error',
    multi_mac:'Multiple MACs' };
  return map[t] || t;
}


// ── Theme sync ──────────────────────────────────────────────────────
// topbar.php is include'd into this page — its themeSwitch already calls:
//   document.documentElement.setAttribute('data-theme', newTheme)
//   document.cookie = "theme=..."
// Since topbar.php's <!DOCTYPE html><html> tags are ignored by the browser
// when nested (the browser uses nac.php's root <html>), document.documentElement
// IS nac.php's <html> — so the toggle works automatically.
// This listener just ensures the cookie is always in sync with the DOM state.
(function() {
  // Apply theme from cookie immediately (in case PHP missed it)
  const cookieTheme = document.cookie.split('; ')
    .find(r => r.startsWith('theme='))?.split('=')[1] || 'dark';
  document.documentElement.setAttribute('data-theme', cookieTheme);

  // Keep cookie in sync whenever data-theme changes (toggle or JS)
  new MutationObserver(() => {
    const t = document.documentElement.getAttribute('data-theme') || 'dark';
    document.cookie = "theme=" + t + "; path=/; max-age=" + (60*60*24*30);
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();

function timeAgo(dateStr) {
  if (!dateStr) return '—';
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
  if (diff < 60)   return 'just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400)return Math.floor(diff/3600) + 'h ago';
  return Math.floor(diff/86400) + 'd ago';
}
// ── MAC History modal (called from MAC tab history button) ──
async function showMacHistory(mac) {
  const existing = document.getElementById('macHistoryModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = 'macHistoryModal';
  modal.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;
    display:flex;align-items:center;justify-content:center`;
  modal.innerHTML = `
    <div style="background:var(--nac-card);border:1px solid var(--nac-border);border-radius:12px;
         width:560px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.4)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div>
          <div style="font-weight:700;font-size:.95rem"><i class="fas fa-history" style="margin-right:6px;color:var(--nac-accent)"></i>MAC History</div>
          <div class="mac-mono" style="color:var(--nac-accent);font-size:.82rem;margin-top:3px">${escHtml(mac)}</div>
        </div>
        <button onclick="document.getElementById('macHistoryModal').remove()"
          style="background:none;border:none;color:var(--nac-muted);font-size:1.3rem;cursor:pointer">✕</button>
      </div>
      <div id="mhm-body" style="font-size:.8rem;color:var(--nac-muted)">
        <div class="spinner" style="display:inline-block;margin-right:6px"></div>Loading…
      </div>
      <div style="border-top:1px solid var(--nac-border);padding-top:12px;margin-top:14px;display:flex;gap:8px">
        <button onclick="traceMac('${escHtml(mac)}',this);document.getElementById('macHistoryModal').remove()"
          style="font-size:.78rem;padding:5px 12px;background:rgba(59,130,246,.15);color:var(--nac-accent);
                 border:1px solid rgba(59,130,246,.3);border-radius:6px;cursor:pointer">
          🔍 Trace MAC
        </button>
      </div>
    </div>`;
  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);

  const events = await nacAjax('mac_history', { mac });
  const body   = document.getElementById('mhm-body');
  if (!body) return;

  if (!events || !events.length) {
    body.innerHTML = '<div style="color:var(--nac-muted);padding:10px 0">No history found for this MAC.</div>';
    return;
  }

  const typeLabels = {
    mac_moved:'Moved', new_mac:'First Seen', vlan_change:'VLAN Change',
    port_down:'Port Down', port_up:'Port Up', mac_lost:'Lost', multi_mac:'Multi-MAC'
  };
  const typeColors = {
    mac_moved:'var(--nac-orange)', new_mac:'var(--nac-accent)', vlan_change:'var(--nac-yellow)',
    port_down:'var(--nac-red)', port_up:'var(--nac-green)',
    mac_lost:'var(--nac-muted)', multi_mac:'var(--nac-purple)'
  };

  body.innerHTML = `<table style="width:100%;border-collapse:collapse">
    <thead><tr style="font-size:.7rem;color:var(--nac-muted);text-transform:uppercase;letter-spacing:.4px">
      <th style="padding:4px 8px 4px 0;text-align:left">Time</th>
      <th style="padding:4px 8px;text-align:left">Event</th>
      <th style="padding:4px 8px;text-align:left">Switch</th>
      <th style="padding:4px 8px;text-align:left">Port / VLAN</th>
      <th style="padding:4px 0;text-align:left">Detail</th>
    </tr></thead>
    <tbody>` +
    events.map(ev => {
      const color = typeColors[ev.event_type] || 'var(--nac-muted)';
      const portInfo = [ev.new_port||ev.old_port, ev.new_vlan||ev.old_vlan]
        .filter(Boolean).join(' · ');
      return `<tr style="border-top:1px solid var(--nac-border)">
        <td style="padding:6px 8px 6px 0;color:var(--nac-muted);white-space:nowrap;font-size:.7rem">${timeAgo(ev.created_at)}</td>
        <td style="padding:6px 8px;white-space:nowrap">
          <span style="color:${color};font-weight:600;font-size:.74rem">${typeLabels[ev.event_type]||ev.event_type}</span>
        </td>
        <td style="padding:6px 8px;font-size:.74rem">${escHtml(ev.switch_name||'—')}</td>
        <td style="padding:6px 8px;font-size:.74rem;font-family:monospace">${escHtml(portInfo||'—')}</td>
        <td style="padding:6px 0;font-size:.7rem;color:var(--nac-muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
            title="${escHtml(ev.details||'')}">${escHtml(ev.details||'')}</td>
      </tr>
      ${ev.acknowledged ? `<tr><td colspan="5" style="padding:2px 0 6px;font-size:.68rem;color:var(--nac-green)">
        ✓ Acked by ${escHtml(ev.ack_by||'unknown')}${ev.ack_note ? ' — '+escHtml(ev.ack_note) : ''}
      </td></tr>` : ''}`;
    }).join('') +
    `</tbody></table>`;
}




// ── SNMP Test ──────────────────────────────────────────────────────────────
async function nacTestSnmp(prefix) {
  const isAdd    = prefix === 'add';
  const ip       = isAdd
    ? (document.getElementById('sw-ip')?.value?.trim() || '')
    : (document.getElementById('edit-sw-ip-hidden')?.value?.trim() || '');
  const community = document.getElementById(isAdd ? 'sw-snmp-community' : 'edit-sw-snmp-community')?.value?.trim() || '';
  const snmpPort  = document.getElementById(isAdd ? 'sw-snmp-port'      : 'edit-sw-snmp-port')?.value || '161';
  const resultEl  = document.getElementById(isAdd ? 'sw-snmp-test-result' : 'edit-sw-snmp-test-result');
  const btn       = document.getElementById(isAdd ? 'sw-snmp-test-btn'    : 'edit-sw-snmp-test-btn');

  if (!ip)        { if(resultEl) resultEl.innerHTML = '<span style="color:var(--nac-yellow)">Enter the switch IP first</span>'; return; }
  if (!community) { if(resultEl) resultEl.innerHTML = '<span style="color:var(--nac-yellow)">Enter community string first</span>'; return; }

  if (btn)  { btn.disabled = true; }
  if (resultEl) resultEl.innerHTML = '<span style="color:var(--nac-muted)"><i class="fas fa-spinner fa-spin" style="margin-right:4px"></i>Testing…</span>';

  try {
    const data = await nacAjax('snmp_test', { ip, community, snmp_port: snmpPort });
    if (!resultEl) return;
    if (data && data.ok) {
      resultEl.innerHTML = `<span style="color:var(--nac-green)"><i class="fas fa-check-circle" style="margin-right:4px"></i>
        OK — <strong>${escHtml(data.sysname || '')}</strong></span>`
        + (data.sysdescr ? `<br><span style="font-size:.72rem;color:var(--nac-muted)">${escHtml(data.sysdescr.substring(0,100))}</span>` : '');
    } else {
      resultEl.innerHTML = `<span style="color:var(--nac-red)"><i class="fas fa-times-circle" style="margin-right:4px"></i>${escHtml((data && data.error) || 'SNMP unreachable')}</span>`;
    }
  } catch(e) {
    if (resultEl) resultEl.innerHTML = `<span style="color:var(--nac-red)">Request failed: ${escHtml(e.message)}</span>`;
  } finally {
    if (btn) btn.disabled = false;
  }
}


// ── Port Bandwidth lazy loader ────────────────────────────────────────────────
// Cache so port bar hover doesn't re-fetch every time
const _bwCache = {};

async function loadPortBw(switchId, portName, cellId) {
  const cell = document.getElementById(cellId);
  if (!cell) return;
  cell.innerHTML = '<span style="color:var(--nac-muted)"><i data-lucide="loader" class="icon-lucide fa-spin"></i></span>';
  const data = await nacAjax('port_bw', { switch_id: switchId, port_name: portName });

  // Cache result for port bar hover
  const cacheKey = `${switchId}::${portName}`;
  _bwCache[cacheKey] = data;

  if (!data) { cell.innerHTML = '<span style="color:var(--nac-red);font-size:.68rem">err</span>'; return; }
  if (!data.ready) {
    cell.innerHTML = `<span style="color:var(--nac-muted);font-size:.68rem" title="${escHtml(data.message||'')}">
      <i data-lucide="clock" class="icon-lucide"></i> pending</span>`;
    return;
  }
  cell.innerHTML = _buildBwCell(data);
}

function _buildBwCell(data) {
  if (!data || !data.ready) return '<span style="color:var(--nac-border)">—</span>';
  const utilPct  = data.util_in !== null ? Math.min(data.util_in, 100) : null;
  const utilColor = utilPct !== null
    ? (utilPct > 80 ? 'var(--nac-red)' : utilPct > 50 ? 'var(--nac-orange)' : 'var(--nac-accent)')
    : 'var(--nac-accent)';
  const utilBar = utilPct !== null
    ? `<div style="margin-top:2px;background:var(--nac-border);border-radius:2px;height:3px;overflow:hidden">
         <div style="width:${utilPct}%;height:100%;background:${utilColor}"></div>
       </div>`
    : '';
  const errors = (data.in_errors + data.out_errors) > 0
    ? `<div style="color:var(--nac-red);font-size:.65rem">⚠ ${data.in_errors+data.out_errors} err</div>` : '';
  const titleTxt = `↓ ${data.in_human} / ↑ ${data.out_human}`
    + (data.speed_human ? ` | Speed: ${data.speed_human}` : '')
    + (data.util_in !== null ? ` | Util: ${data.util_in}%` : '');
  return `<div style="line-height:1.4" title="${escHtml(titleTxt)}">
    <div style="color:var(--nac-green);font-size:.68rem">↓ ${escHtml(data.in_human)}</div>
    <div style="color:var(--nac-accent);font-size:.68rem">↑ ${escHtml(data.out_human)}</div>
    ${utilBar}${errors}
  </div>`;
}

// Auto-load bandwidth for all up ports when detail panel opens
function loadAllPortBw(switchId, ports) {
  ports.forEach((p, i) => {
    if (p.admin_status === 'up' && p.link_status === 'up') {
      const bwId = `bw-${switchId}-${p.port_name.replace(/[^a-z0-9]/gi,'_')}`;
      // Stagger requests slightly to avoid hammering the server
      setTimeout(() => loadPortBw(switchId, p.port_name, bwId), i * 60);
    }
  });
}

// ── Port bar BW tooltip on hover ──────────────────────────────────────────────
// Attach once — event delegation on document
(function() {
  let _tip = null;
  function getTip() {
    if (!_tip) {
      _tip = document.createElement('div');
      _tip.id = 'pb-bw-tip';
      _tip.style.cssText = `position:fixed;z-index:9999;background:var(--nac-surface);
        border:1px solid var(--nac-border);border-radius:7px;padding:8px 12px;
        font-size:.75rem;box-shadow:0 6px 20px rgba(0,0,0,.4);pointer-events:none;
        min-width:140px;display:none;line-height:1.6;`;
      document.body.appendChild(_tip);
    }
    return _tip;
  }

  document.addEventListener('mouseover', async e => {
    const slot = e.target.closest('.pb-slot');
    if (!slot) return;
    const sw   = slot.dataset.sw;
    const port = slot.dataset.port;
    if (!sw || !port) return;

    const tip = getTip();
    // Position near cursor
    tip.style.display = 'block';
    tip.innerHTML = `<div style="color:var(--nac-accent);font-weight:600;margin-bottom:4px;font-family:monospace">${escHtml(port)}</div>
      <div style="color:var(--nac-muted);font-size:.68rem"><i data-lucide="loader" class="icon-lucide fa-spin"></i> loading…</div>`;

    const cacheKey = `${sw}::${port}`;
    let data = _bwCache[cacheKey];
    if (!data) {
      data = await nacAjax('port_bw', { switch_id: sw, port_name: port });
      _bwCache[cacheKey] = data;
    }

    // Bail if mouse already left
    if (tip.style.display === 'none') return;

    let body = '';
    if (!data || !data.ready) {
      body = `<div style="color:var(--nac-muted);font-size:.7rem">${escHtml((data && data.message) || 'No BW data yet')}</div>`;
    } else {
      const utilColor = data.util_in !== null
        ? (data.util_in > 80 ? 'var(--nac-red)' : data.util_in > 50 ? 'var(--nac-orange)' : 'var(--nac-green)')
        : 'var(--nac-green)';
      const utilBar = data.util_in !== null
        ? `<div style="margin:4px 0 2px;background:var(--nac-border);border-radius:2px;height:4px;overflow:hidden">
             <div style="width:${Math.min(data.util_in,100)}%;height:100%;background:${utilColor}"></div>
           </div>
           <div style="font-size:.67rem;color:var(--nac-muted)">${data.util_in}% utilisation</div>` : '';
      const errRow = (data.in_errors + data.out_errors) > 0
        ? `<div style="color:var(--nac-red);font-size:.68rem;margin-top:3px">⚠ ${data.in_errors+data.out_errors} errors</div>` : '';
      body = `<div style="color:var(--nac-green)">↓ In &nbsp; ${escHtml(data.in_human)}</div>
              <div style="color:var(--nac-accent)">↑ Out  ${escHtml(data.out_human)}</div>
              ${data.speed_human ? `<div style="color:var(--nac-muted);font-size:.68rem">Speed: ${escHtml(data.speed_human)}</div>` : ''}
              ${utilBar}${errRow}`;
    }
    tip.innerHTML = `<div style="color:var(--nac-accent);font-weight:600;margin-bottom:5px;font-family:monospace">${escHtml(port)}</div>${body}`;
  });

  document.addEventListener('mousemove', e => {
    const tip = document.getElementById('pb-bw-tip');
    if (!tip || tip.style.display === 'none') return;
    const x = e.clientX + 14, y = e.clientY + 14;
    tip.style.left = Math.min(x, window.innerWidth  - tip.offsetWidth  - 10) + 'px';
    tip.style.top  = Math.min(y, window.innerHeight - tip.offsetHeight - 10) + 'px';
  });

  document.addEventListener('mouseout', e => {
    const slot = e.target.closest('.pb-slot');
    if (!slot) return;
    const tip = document.getElementById('pb-bw-tip');
    if (tip) tip.style.display = 'none';
  });
})();

// ── Analytics panel ───────────────────────────────────────────────────────────
let _analyticsData = null;
let _analyticsActiveTab = 'topo';

async function openAnalyticsAndShow(tab) {
  _analyticsActiveTab = tab || 'topo';
  const panel = document.getElementById('analyticsPanel');
  panel.style.display = 'block';
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

  // Highlight active button
  ['topo','vlan','access','trunk','unknown','new','rogue'].forEach(t => {
    const btn = document.getElementById('analytics-tab-'+t);
    if (btn) btn.style.borderColor = t === _analyticsActiveTab ? 'var(--nac-accent)' : '';
  });

  if (_analyticsData) {
    renderAnalytics(_analyticsData, _analyticsActiveTab);
    return;
  }

  document.getElementById('analytics-loading').style.display = 'block';
  ['topo','vlan','access','trunk','unknown','new','rogue'].forEach(t => {
    document.getElementById('analytics-'+t).style.display = 'none';
  });

  const resp = await nacAjax('analytics');
  document.getElementById('analytics-loading').style.display = 'none';
  if (!resp || !resp.ok) {
    document.getElementById('analytics-loading').innerHTML =
      '<span style="color:var(--nac-red)">Failed to load analytics</span>';
    return;
  }
  _analyticsData = resp.data;
  renderAnalytics(_analyticsData, _analyticsActiveTab);
}

function showAnalyticsTab(tab) {
  _analyticsActiveTab = tab;
  ['topo','vlan','access','trunk','unknown','new','rogue'].forEach(t => {
    const el  = document.getElementById('analytics-'+t);
    const btn = document.getElementById('analytics-tab-'+t);
    if (el)  el.style.display  = t === tab ? '' : 'none';
    if (btn) btn.style.borderColor = t === tab ? 'var(--nac-accent)' : '';
  });
  if (_analyticsData) renderAnalytics(_analyticsData, tab);
}

function renderAnalytics(d, tab) {
  document.getElementById('analytics-loading').style.display = 'none';
  ['topo','vlan','access','trunk','unknown','new','rogue'].forEach(t => {
    document.getElementById('analytics-'+t).style.display = t === tab ? '' : 'none';
  });
  // Update active tab button
  ['topo','vlan','access','trunk','unknown','new','rogue'].forEach(t => {
    const btn = document.getElementById('analytics-tab-'+t);
    if (btn) btn.style.borderColor = t === tab ? 'var(--nac-accent)' : '';
  });

  if (tab === 'topo')    renderTopoTab(d);
  if (tab === 'vlan')    renderVlanTab(d);
  if (tab === 'access')  renderPerSwitchTab(d, 'access');
  if (tab === 'trunk')   renderPerSwitchTab(d, 'trunk');
  if (tab === 'unknown') renderUnknownTab(d);
  if (tab === 'new')     renderNewMacsTab(d);
  if (tab === 'rogue')   renderRogueTab(d);
}

function renderTopoTab(d) {
  const el = document.getElementById('analytics-topo-content');
  if (!d.topology_links || !d.topology_links.length) {
    el.innerHTML = `<div style="color:var(--nac-muted);padding:20px;text-align:center">
      <i class="fas fa-info-circle" style="margin-right:6px"></i>
      No trunk connections discovered yet. Poll your switches to populate LLDP topology data.
    </div>`;
    return;
  }
  let html = `<table class="port-table" style="width:100%">
    <thead><tr><th>Switch A</th><th>Port</th><th>↔</th><th>Port</th><th>Switch B</th><th>Type</th><th>MACs via link</th><th>Discovered</th></tr></thead><tbody>`;
  d.topology_links.forEach(l => {
    html += `<tr>
      <td><strong style="color:var(--nac-accent)">${escHtml(l.switch_a_name)}</strong>
          <div style="font-size:.7rem;color:var(--nac-muted)">${escHtml(l.switch_a_ip)}</div></td>
      <td><span class="port-name">${escHtml(l.port_a)}</span></td>
      <td style="text-align:center;color:var(--nac-muted)">⟷</td>
      <td><span class="port-name">${escHtml(l.port_b)}</span></td>
      <td><strong style="color:var(--nac-accent)">${escHtml(l.switch_b_name)}</strong>
          <div style="font-size:.7rem;color:var(--nac-muted)">${escHtml(l.switch_b_ip)}</div></td>
      <td><span style="font-size:.7rem;background:rgba(88,166,255,.1);color:var(--nac-accent);padding:2px 7px;border-radius:4px">${escHtml(l.link_type||'lldp')}</span></td>
      <td style="text-align:center">${l.mac_count_a||0}</td>
      <td style="font-size:.7rem;color:var(--nac-muted)">${timeAgo(l.discovered_at)}</td>
    </tr>`;
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}

function renderVlanTab(d) {
  const el = document.getElementById('analytics-vlan-content');
  if (!d.vlan_distribution || !d.vlan_distribution.length) {
    el.innerHTML = '<div style="color:var(--nac-muted);padding:20px;text-align:center">No VLAN data yet</div>';
    return;
  }
  const max = d.vlan_distribution[0].mac_count;
  let html = `<div style="display:flex;flex-direction:column;gap:6px">`;
  d.vlan_distribution.forEach(v => {
    const pct = Math.round((v.mac_count / max) * 100);
    const colors = ['#58a6ff','#3fb950','#a371f7','#f97316','#e3b341','#06b6d4'];
    const color  = colors[Math.abs(v.vlan_name.charCodeAt(0)) % colors.length];
    html += `<div style="display:flex;align-items:center;gap:10px">
      <div style="width:120px;font-size:.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
           title="${escHtml(v.vlan_name)}">${escHtml(v.vlan_name)}</div>
      <div style="flex:1;background:var(--nac-border);border-radius:3px;height:14px;overflow:hidden">
        <div style="width:${pct}%;height:100%;background:${color};border-radius:3px;transition:width .4s"></div>
      </div>
      <div style="width:80px;font-size:.75rem;color:var(--nac-muted);white-space:nowrap">
        ${v.mac_count} MACs · ${v.switch_count} SW</div>
    </div>`;
  });
  html += '</div>';
  el.innerHTML = html;
}

function renderPerSwitchTab(d, type) {
  const el  = document.getElementById('analytics-'+type+'-content');
  const key = type === 'access' ? 'access_macs' : 'trunk_macs';
  if (!d.per_switch || !d.per_switch.length) {
    el.innerHTML = '<div style="color:var(--nac-muted);padding:20px;text-align:center">No data yet</div>';
    return;
  }
  const max = Math.max(...d.per_switch.map(s => parseInt(s[key]||0))) || 1;
  let html = `<table class="port-table" style="width:100%">
    <thead><tr><th>Switch</th><th>IP</th><th>${type==='access'?'Access':'Trunk'} MACs</th><th>Distribution</th><th>Up Ports</th></tr></thead><tbody>`;
  d.per_switch.forEach(s => {
    const cnt  = parseInt(s[key]||0);
    const pct  = Math.round((cnt / max) * 100);
    const color= type === 'access' ? 'var(--nac-green)' : 'var(--nac-accent)';
    const ports= type === 'access' ? s.access_ports_up : s.trunk_ports_up;
    html += `<tr>
      <td><strong>${escHtml(s.hostname)}</strong>${s.model?`<div style="font-size:.7rem;color:var(--nac-muted)">${escHtml(s.model)}</div>`:''}</td>
      <td style="font-family:monospace;font-size:.78rem">${escHtml(s.ip)}</td>
      <td style="text-align:center;font-weight:700;color:${color}">${cnt}</td>
      <td style="min-width:120px">
        <div style="background:var(--nac-border);border-radius:3px;height:10px;overflow:hidden">
          <div style="width:${pct}%;height:100%;background:${color};transition:width .4s"></div>
        </div>
      </td>
      <td style="text-align:center;color:var(--nac-muted)">${ports||0}</td>
    </tr>`;
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}

function renderUnknownTab(d) {
  const el = document.getElementById('analytics-unknown-content');
  const cnt = d.unknown_upstream_macs || 0;
  if (cnt === 0) {
    el.innerHTML = `<div style="color:var(--nac-green);padding:20px;text-align:center">
      <i class="fas fa-check-circle" style="font-size:1.5rem;display:block;margin-bottom:6px"></i>
      All trunk-facing MACs have a known upstream switch. Great coverage!
    </div>`;
    return;
  }
  el.innerHTML = `<div style="background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.25);border-radius:8px;padding:16px;margin-bottom:14px">
    <div style="font-size:1.4rem;font-weight:700;color:var(--nac-orange)">${cnt}</div>
    <div style="font-size:.82rem;color:var(--nac-text);margin-top:4px">
      MAC addresses are visible on trunk ports but their source switches are not in Janus.
      ${d.unknown_upstream_ports||0} trunk port(s) affected.
    </div>
    <div style="font-size:.78rem;color:var(--nac-muted);margin-top:8px">
      <strong>Action:</strong> Check LLDP neighbors on those trunk ports and add the connected switches via <strong>Add Switch</strong>.
    </div>
  </div>`;
}

function renderNewMacsTab(d) {
  const el = document.getElementById('analytics-new-content');
  if (!d.new_macs_24h || !d.new_macs_24h.length) {
    el.innerHTML = '<div style="color:var(--nac-muted);padding:20px;text-align:center">No new MACs in the last 24 hours</div>';
    return;
  }
  let html = `<table class="port-table" style="width:100%">
    <thead><tr><th>MAC</th><th>Switch</th><th>Port</th><th>VLAN</th><th>First Seen</th></tr></thead><tbody>`;
  d.new_macs_24h.forEach(m => {
    html += `<tr>
      <td><span class="mac-mono">${escHtml(m.mac)}</span></td>
      <td style="font-size:.78rem">${escHtml(m.switch_name||'—')}</td>
      <td><span class="port-name" style="font-size:.75rem">${escHtml(m.new_port||'—')}</span></td>
      <td><span class="vlan-badge">${escHtml(m.new_vlan||'—')}</span></td>
      <td style="font-size:.72rem;color:var(--nac-muted)">${timeAgo(m.created_at)}</td>
    </tr>`;
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}

function renderRogueTab(d) {
  const el = document.getElementById('analytics-rogue-content');
  if (!d.multi_mac_ports || !d.multi_mac_ports.length) {
    el.innerHTML = `<div style="color:var(--nac-green);padding:20px;text-align:center">
      <i class="fas fa-check-circle" style="font-size:1.5rem;display:block;margin-bottom:6px"></i>
      No access ports with multiple MACs detected.
    </div>`;
    return;
  }
  let html = `<table class="port-table" style="width:100%">
    <thead><tr><th>Switch</th><th>Port</th><th>MAC Count</th><th>VLAN</th><th>Risk</th></tr></thead><tbody>`;
  d.multi_mac_ports.forEach(p => {
    const risk = p.mac_count >= 8
      ? `<span style="color:var(--nac-red);font-weight:700">HIGH — unmanaged SW?</span>`
      : p.mac_count >= 4
        ? `<span style="color:var(--nac-orange)">MEDIUM — hub/pool</span>`
        : `<span style="color:var(--nac-yellow)">LOW — check</span>`;
    html += `<tr>
      <td style="font-size:.78rem">${escHtml(p.switch_name)}</td>
      <td><span class="port-name">${escHtml(p.port_name)}</span></td>
      <td style="text-align:center;font-weight:700;color:var(--nac-orange)">${p.mac_count}</td>
      <td><span class="vlan-badge">${escHtml(p.vlan_names||'—')}</span></td>
      <td style="font-size:.75rem">${risk}</td>
    </tr>`;
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}

</script>

</body>
</html>
