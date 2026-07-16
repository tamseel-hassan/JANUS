<?php
/**
 * Janus Vulnerability Scanner v9.0
 * Enterprise-grade infrastructure & vulnerability assessment
 * Uses Nmap with vulners script for CVE detection.
 */

session_start();
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../db_config.php';
date_default_timezone_set('Asia/Karachi');

$scan_dir = '/tmp/janus_scans/';
if (!file_exists($scan_dir)) { mkdir($scan_dir, 0777, true); }

// scanner_records table is created by the master schema (janus_fresh_schema.sql).
// Columns: id, target_ip, scan_type, result (JSON), scanned_by, scanned_at
// Do NOT recreate here — the inline definition had different columns
// (open_ports, vuln_count, duration_secs, raw_results) causing every INSERT to fail.

$profiles = [
    'quick'   => ['name' => 'Quick Scan',      'desc' => 'Top 100 ports, fast',                    'args' => '-F -T4 --open'],
    'fulltcp' => ['name' => 'Full TCP Scan',    'desc' => 'All 65535 TCP ports, SYN stealth',       'args' => '-sS -p- -T4 --open --min-rate 1000'],
    'udp'     => ['name' => 'UDP Scan',         'desc' => 'Top 1000 UDP ports',                     'args' => '-sU -T4 --open --max-retries 1'],
    'os'      => ['name' => 'OS & Service',     'desc' => 'OS fingerprint, version detection',     'args' => '-A -T4 --open'],
    'vuln'    => ['name' => 'Vulnerability (CVE)', 'desc' => 'Run vulners script for CVEs',        'args' => '-sV --script vulners --script-args mincvss=5.0 -T4 --open'],
    'custom'  => ['name' => 'Custom',           'desc' => 'Enter any Nmap arguments',               'args' => ''],
];

// Handle scan submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['target'])) {
    $target = trim($_POST['target']);
    $profile = $_POST['profile'] ?? 'quick';
    $custom_args = $_POST['custom_args'] ?? '';

    $targets = array_map('trim', explode(',', $target));
    $valid = array_filter($targets, function($t) { return filter_var(explode('/', $t)[0], FILTER_VALIDATE_IP) || filter_var($t, FILTER_VALIDATE_DOMAIN); });
    if (empty($valid)) { $_SESSION['scan_error'] = 'Invalid target.'; header('Location: vuln_scan.php'); exit; }
    $target_str = implode(' ', array_map('escapeshellarg', $valid));

    if ($profile === 'custom') $args = escapeshellcmd($custom_args);
    else $args = $profiles[$profile]['args'] ?? '-F';

    $scan_id = uniqid('scan_');
    $xml_file = $scan_dir . $scan_id . '.xml';
    $cmd = 'nohup nmap ' . $args . ' -oX ' . escapeshellarg($xml_file) . ' ' . $target_str . ' > /dev/null 2>&1 &';
    file_put_contents($scan_dir . $scan_id . '.start', time());
    exec($cmd);
    header('Location: vuln_scan.php?scan_id=' . $scan_id . '&target=' . urlencode($target) . '&profile=' . $profile);
    exit;
}

// AJAX status polling
if (isset($_GET['action']) && $_GET['action'] === 'poll_status') {
    header('Content-Type: application/json');
    $scan_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['scan_id'] ?? '');
    $xml_file = $scan_dir . $scan_id . '.xml';
    if (file_exists($xml_file) && filesize($xml_file) > 0) {
        $content = file_get_contents($xml_file);
        if (strpos($content, '</nmaprun>') !== false) {
            $duration = time() - (int)(file_get_contents($scan_dir . $scan_id . '.start') ?? time());
            $result = parse_nmap_xml($content, $con, $_GET['target'] ?? '', $_GET['profile'] ?? 'quick', $duration);
            echo json_encode(['status' => 'complete', 'result' => $result]);
            @unlink($xml_file); @unlink($scan_dir . $scan_id . '.start');
            exit;
        }
    }
    echo json_encode(['status' => 'running']);
    exit;
}

function parse_nmap_xml($xml_content, $con, $target, $profile, $duration) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_content);
    if (!$xml) return null;
    $ports = []; $vuln_count = 0; $os_matches = [];
    if (isset($xml->host->os->osmatch)) foreach ($xml->host->os->osmatch as $os) $os_matches[] = ['name' => (string)$os['name'], 'accuracy' => (int)$os['accuracy']];
    if (isset($xml->host->ports->port)) {
        foreach ($xml->host->ports->port as $p) {
            $vulns = [];
            if (isset($p->script)) foreach ($p->script as $s) {
                $vulns[] = ['id' => (string)$s['id'], 'output' => (string)$s['output']];
                if ($s['id'] == 'vulners') { preg_match_all('/CVE-\d{4}-\d+/', $s->output, $cves); $vuln_count += count($cves[0]); }
            }
            $ports[] = ['port' => (int)$p['portid'], 'protocol' => (string)$p['protocol'], 'service' => (string)$p->service['name'], 'product' => (string)$p->service['product'], 'version' => (string)$p->service['version'], 'state' => (string)$p->state['state'], 'vulns' => $vulns];
        }
    }
    // Build result JSON matching the master schema's `result` column
    $result_data = [
        'ports'      => $ports,
        'os_matches' => $os_matches,
        'vuln_count' => $vuln_count,
        'duration'   => $duration,
        'open_ports' => count($ports),
    ];
    $result_json = mysqli_real_escape_string($con, json_encode($result_data));
    $t = mysqli_real_escape_string($con, $target);
    $p = mysqli_real_escape_string($con, $profile);
    mysqli_query($con, "INSERT INTO scanner_records (target_ip, scan_type, result) VALUES ('$t', '$p', '$result_json')");
    return $result_data;
}

$history = [];
$res = mysqli_query($con, "SELECT * FROM scanner_records ORDER BY scanned_at DESC LIMIT 20");
if ($res) while ($row = mysqli_fetch_assoc($res)) $history[] = $row;
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vulnerability Scanner | Janus</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet">
    <link href="/css/font-awesome/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/theme.css">
    <style>
        .scan-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; }
        .cve-badge { background: #dc3545; color: white; font-size: 0.75rem; }
        .os-badge { background: var(--accent); color: white; }
        @keyframes pulse { 0%{opacity:0.5;} 50%{opacity:1;} 100%{opacity:0.5;} }
        .scanning-pulse { animation: pulse 1.5s infinite; }
    </style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; ?>
<?php include __DIR__ . '/../../sidebar.php'; ?>
<div id="main-content" style="padding:80px 24px 32px; margin-left:260px;">
    <div class="container-fluid" style="max-width:1200px;">
        <h2 class="mb-4"><i class="fas fa-shield-alt me-2"></i>Vulnerability Scanner</h2>
        <ul class="nav nav-pills mb-4">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#newScan"><i class="fas fa-play me-1"></i>New Scan</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#history"><i class="fas fa-history me-1"></i>Scan History</a></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="newScan">
                <?php if (!empty($_SESSION['scan_error'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['scan_error']); unset($_SESSION['scan_error']); ?></div>
                <?php endif; ?>
                <div class="row"><div class="col-lg-8">
                    <div class="scan-card p-4 mb-4">
                        <form method="post" id="scanForm">
                            <div class="mb-3"><label class="form-label fw-bold"><i class="fas fa-crosshairs me-1"></i>Target(s)</label><input type="text" name="target" class="form-control form-control-lg" placeholder="192.168.1.1, example.com" required></div>
                            <div class="mb-3"><label class="form-label fw-bold"><i class="fas fa-tachometer-alt me-1"></i>Profile</label><div class="row g-2">
                                <?php $i = 0; foreach ($profiles as $key => $p): ?>
                                    <div class="col-md-4"><input type="radio" class="btn-check" name="profile" id="profile_<?=$key?>" value="<?=$key?>" <?= $i==0 ? 'checked' : '' ?>><label class="btn btn-outline-primary w-100 h-100 p-3" for="profile_<?=$key?>"><strong><?=$p['name']?></strong><br><small class="text-muted"><?=$p['desc']?></small></label></div>
                                <?php $i++; endforeach; ?>
                            </div></div>
                            <div class="mb-3" id="customArgsBox" style="display:none;"><label class="form-label"><i class="fas fa-terminal me-1"></i>Custom Arguments</label><input type="text" name="custom_args" class="form-control font-monospace" placeholder="-sS -sV -p 1-1000"></div>
                            <button type="submit" class="btn btn-success btn-lg w-100"><i class="fas fa-bolt me-2"></i>Start Scan</button>
                        </form>
                    </div>
                </div></div>
                <div id="scanStatus" class="scan-card p-5 text-center d-none"><div class="spinner-border spinner-border-lg mb-3"></div><h4 class="scanning-pulse">Scanning...</h4><p class="text-muted" id="statusTarget"></p></div>
                <div id="scanResults" class="mt-4 d-none"></div>
            </div>
            <div class="tab-pane fade" id="history"><div class="scan-card p-3"><h5><i class="fas fa-list-alt me-1"></i>Recent Scans</h5>
                <?php if (empty($history)): ?><div class="text-muted text-center py-4">No history</div><?php else: ?><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Target</th><th>Profile</th><th>Date</th><th>Ports</th><th>Vulns</th><th>Duration</th></tr></thead><tbody>
                <?php foreach ($history as $h): ?><tr><td><code><?=htmlspecialchars($h['target_ip'])?></code></td><td><span class="badge bg-primary"><?=htmlspecialchars($h['scan_type'])?></span></td><td><?=date('Y-m-d H:i', strtotime($h['scanned_at']))?></td><td><?= isset($h['result']) ? (json_decode($h['result'],true)['open_ports'] ?? '—') : '—' ?></td><td><?= isset($h['result']) ? (json_decode($h['result'],true)['vuln_count'] ?? '—') : '—' ?></td><td><?= isset($h['result']) ? round(json_decode($h['result'],true)['duration'] ?? 0, 1).'s' : '—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>
        </div>
    </div>
</div>
<script src="/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('input[name="profile"]').forEach(r=>r.addEventListener('change', ()=>document.getElementById('customArgsBox').style.display = r.value==='custom'?'block':'none'));
const urlParams = new URLSearchParams(window.location.search), scanId = urlParams.get('scan_id'), target = urlParams.get('target'), profile = urlParams.get('profile');
if(scanId) {
    document.getElementById('scanStatus').classList.remove('d-none');
    document.getElementById('statusTarget').textContent = 'Target: '+target+' | Profile: '+profile;
    let poll = setInterval(async ()=>{
        const r = await fetch('vuln_scan.php?action=poll_status&scan_id='+scanId+'&target='+encodeURIComponent(target)+'&profile='+profile);
        const d = await r.json();
        if(d.status==='complete'){ clearInterval(poll); displayResults(d.result); document.getElementById('scanStatus').classList.add('d-none'); }
    },3000);
}
function displayResults(r) {
    const c = document.getElementById('scanResults'); c.classList.remove('d-none');
    let h = '<div class="scan-card p-4"><h4 class="mb-3"><i class="fas fa-check-circle text-success me-2"></i>Scan Complete</h4>';
    if(r.os_matches.length) { h += '<div class="mb-3"><strong>OS:</strong> '; r.os_matches.forEach(os=>h+=`<span class="badge os-badge me-1">${os.name} (${os.accuracy}%)</span>`); h+='</div>'; }
    h += `<p><strong>Duration:</strong> ${r.duration}s | <strong>Ports:</strong> ${r.ports.length} | <strong>Vulns:</strong> ${r.vuln_count}</p>`;
    h += '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Port</th><th>Service</th><th>Version</th><th>State</th><th>Vulns</th></tr></thead><tbody>';
    r.ports.forEach(p=>{
        h += `<tr><td><code>${p.port}/${p.protocol}</code></td><td>${p.service}</td><td>${p.product?p.product+' '+p.version:'<span class="text-muted">Unknown</span>'}</td><td><span class="badge bg-${p.state==='open'?'success':'secondary'}">${p.state}</span></td><td>`;
        if(p.vulns.length) p.vulns.forEach(v=>{ h += `<div class="mb-1"><span class="badge cve-badge">${v.id}</span>`; const cve = v.output.match(/CVE-\d{4}-\d+/); if(cve) h += ` <a href="https://nvd.nist.gov/vuln/detail/${cve[0]}" target="_blank" class="text-danger small">${cve[0]}</a>`; h += `<div class="small text-muted">${v.output.substring(0,200)}</div></div>`; });
        else h += '<span class="text-muted">None</span>';
        h += '</td></tr>';
    });
    h += '</tbody></table></div></div>'; c.innerHTML = h;
}
document.addEventListener('DOMContentLoaded', ()=>{
    const s=document.getElementById('sidebar'), m=document.getElementById('main-content');
    function a(){ m.style.marginLeft = s?.classList.contains('collapsed')?'80px':'260px'; }
    a(); if(s) new MutationObserver(a).observe(s,{attributes:true, attributeFilter:['class']});
});
</script>
</body>
</html>
