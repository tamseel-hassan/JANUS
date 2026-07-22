<?php
/* resources.php – Resource Utilization (Janus NMS) */
session_start();
require_once __DIR__ . '/db_config.php';
if (!isset($_SESSION['loggedin'])) { header('Location: index.html'); exit; }

date_default_timezone_set('Asia/Karachi');

/* ---------- Helpers ---------- */
function formatBytes($b, $p = 2) {
    if (!$b) return '0 B';
    $u = ['B','KB','MB','GB','TB'];
    $pow = floor(log($b, 1024));
    return round($b/pow(1024,$pow), $p).' '.$u[$pow];
}
function formatBits($b) {
    if (!$b) return '0 bps';
    if ($b<1000) return round($b,1).' bps';
    if ($b<1e6) return round($b/1e3,1).' Kbps';
    if ($b<1e9) return round($b/1e6,1).' Mbps';
    return round($b/1e9,1).' Gbps';
}
function decodeHex($s) {
    if (strpos($s,'Hex-STRING:')===false) return $s;
    preg_match('/Hex-STRING:\s*([0-9A-Fa-f\s]+)/',$s,$m);
    $hex = preg_split('/\s+/',trim($m[1]));
    $r = '';
    foreach ($hex as $h) if (ctype_xdigit($h)) $r .= chr(hexdec($h));
    return trim($r," \t\n\r\0\x0B\x00") ?: $s;
}

/* Device list */
$dl = []; $lr = mysqli_query($con,"SELECT id,name,ip FROM devices WHERE snmp_community IS NOT NULL AND snmp_community != '' ORDER BY name");
while ($r = mysqli_fetch_assoc($lr)) $dl[$r['id']]=$r;

$sid = $_GET['device'] ?? null;
$all = empty($sid) || !isset($dl[$sid]);
$wh = $all ? "WHERE d.snmp_community IS NOT NULL AND d.snmp_community != ''" : "WHERE d.id = ".(int)$sid;

$dq = "
    SELECT d.id, d.name, d.ip, d.type, d.model,
           COALESCE(pl.status,'down') ping_status,
           sm.cpu_usage, sm.memory_total, sm.memory_used, sm.memory_free,
           sm.disk_total, sm.disk_used, sm.disk_free,
           sm.load_1min, sm.load_5min, sm.load_15min, sm.processes,
           sm.checked_at last_snmp_check,
           pl.checked_at last_ping_check
    FROM devices d
    LEFT JOIN (
        SELECT device_id, status, checked_at,
               ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn
        FROM ping_logs WHERE link_id IS NULL
    ) pl ON d.id = pl.device_id AND pl.rn = 1
    LEFT JOIN (
        SELECT device_id, cpu_usage, memory_total, memory_used, memory_free,
               disk_total, disk_used, disk_free, load_1min, load_5min, load_15min,
               processes, checked_at,
               ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn
        FROM snmp_metrics
    ) sm ON d.id = sm.device_id AND sm.rn = 1
    $wh ORDER BY d.name
";
$devs = []; $res = mysqli_query($con,$dq);
if (!$res) die('Query error: '.mysqli_error($con));
while ($r = mysqli_fetch_assoc($res)) $devs[] = $r;

/* Interface traffic - BPS from interface_traffic + cumulative bytes from snmp_interfaces */
$ifstats = []; $ids = array_column($devs,'id');
if ($ids) {
    $ids = implode(',',array_map('intval',$ids));
    $iq = "
        SELECT it.device_id, it.if_index, it.if_name,
               it.bytes_in, it.bytes_out, it.traffic_in_bps, it.traffic_out_bps,
               it.checked_at, d.name device_name, d.ip device_ip,
               COALESCE(si.if_speed, 0) if_speed, si.if_descr,
               COALESCE(si.bytes_in,  it.bytes_in)  si_bytes_in,
               COALESCE(si.bytes_out, it.bytes_out) si_bytes_out
        FROM interface_traffic it
        JOIN devices d ON it.device_id = d.id
        LEFT JOIN snmp_interfaces si ON it.device_id = si.device_id AND it.if_index = si.if_index
        WHERE it.device_id IN ($ids)
          AND it.checked_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
          AND it.id IN (
              SELECT MAX(id) FROM interface_traffic
              WHERE device_id = it.device_id AND if_index = it.if_index
              GROUP BY device_id, if_index
          )
        ORDER BY it.device_id, it.if_index
    ";
    $ir = mysqli_query($con,$iq);
    while ($r = mysqli_fetch_assoc($ir)) $ifstats[$r['device_id']][] = $r;
}

/* Also pull snmp_interfaces for devices that have NO interface_traffic yet (brand-new first run) */
$rawIfStats = []; 
$riq = "
    SELECT si.device_id, si.if_index, si.if_descr as if_name,
           si.bytes_in, si.bytes_out, 0 as traffic_in_bps, 0 as traffic_out_bps,
           si.checked_at, si.if_speed,
           si.bytes_in as si_bytes_in, si.bytes_out as si_bytes_out
    FROM snmp_interfaces si
    WHERE si.device_id IN ($ids)
      AND si.checked_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ORDER BY si.device_id, si.if_index
";
$rir = mysqli_query($con, $riq);
while ($r = mysqli_fetch_assoc($rir)) $rawIfStats[$r['device_id']][] = $r;

/* Merge: use interface_traffic if available, else fall back to snmp_interfaces */
foreach ($rawIfStats as $did => $rawList) {
    if (!isset($ifstats[$did])) {
        $ifstats[$did] = $rawList;
    }
}

/* Totals */
$td = count($devs); $sw=0; $tcpu=$tmem=$tdisk=0; $tin=$tout=0; $tif=0;
$firstRunDevices = []; // track devices that only have raw bytes, no BPS yet
foreach ($devs as $d) {
    if ($d['cpu_usage'] !== null) {
        $sw++; $tcpu += (float)$d['cpu_usage'];
        if ($d['memory_total']>0) $tmem += ($d['memory_used']/$d['memory_total'])*100;
        if ($d['disk_total']>0)   $tdisk += ($d['disk_used']/$d['disk_total'])*100;
    }
    if (isset($ifstats[$d['id']])) {
        $hasBps = false;
        foreach ($ifstats[$d['id']] as $if) {
            $bpsIn  = $if['traffic_in_bps']??0;
            $bpsOut = $if['traffic_out_bps']??0;
            $tin  += $bpsIn;
            $tout += $bpsOut;
            $tif++;
            if ($bpsIn > 0 || $bpsOut > 0) $hasBps = true;
        }
        if (!$hasBps) $firstRunDevices[$d['id']] = true;
    }
}
$acpu = $sw ? round($tcpu/$sw,1) : 0;
$amem = $sw ? round($tmem/$sw,1) : 0;
$adisk = $sw ? round($tdisk/$sw,1) : 0;
$sr = $td ? round(($sw/$td)*100,1) : 0;

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Utilization - Janus</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="css/theme.css">
    <style>
        .progress-circle{--size:80px;--bg-color:rgba(73,80,87,.5);--value:0;--fg-color:#007bff;width:var(--size);height:var(--size);border-radius:50%;background:radial-gradient(closest-side,var(--card-bg) 80%,transparent 0 99.9%,var(--card-bg) 0),conic-gradient(var(--fg-color) calc(var(--value)*1%),var(--bg-color) 0);display:flex;align-items:center;justify-content:center;position:relative;transition:all .3s}
        .progress-circle::before{content:attr(data-value)'%';font-weight:bold}
        .cpu-high{--fg-color:#dc3545}.cpu-medium{--fg-color:#ffc107}.cpu-low{--fg-color:#28a745}
        .traffic-in{color:#28a745;font-weight:600}.traffic-out{color:#dc3545;font-weight:600}
        .network-stat-value{font-size:1.5rem;font-weight:bold;color:var(--accent)}
        .bandwidth-bar{height:4px;background:rgba(13,202,240,.2);border-radius:2px;overflow:hidden;margin-top:3px}
        .bandwidth-fill{height:100%;background:linear-gradient(90deg,#28a745,#0dcaf0);transition:width .3s}
    </style>
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content" style="padding:80px 24px 32px;margin-left:260px;">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="fas fa-network-wired"></i> Resource Utilization</h2>
                <p class="lead">Real-time monitoring of device performance</p>
            </div>
        </div>
        <?php if (!empty($dl)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card p-3">
                    <label class="form-label"><i class="fas fa-filter me-2"></i>Filter by Device</label>
                    <div class="dropdown d-inline-block">
                        <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-server me-2"></i>
                            <?= $sid && isset($dl[$sid]) ? htmlspecialchars($dl[$sid]['name']) : 'All Devices' ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="?">All Devices</a></li>
                            <?php foreach ($dl as $id => $dev): ?>
                            <li><a class="dropdown-item" href="?device=<?=$id?>"><?= htmlspecialchars($dev['name']) ?> (<?=$dev['ip']?>)</a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (empty($devs)): ?>
        <div class="card mt-4 p-4 text-center">
            <i class="fas fa-server fa-3x text-muted mb-3"></i>
            <h5>No SNMP Devices Found</h5>
            <p class="text-muted">Add SNMP credentials in Manage Devices.</p>
        </div>
        <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card text-center p-3"><div class="progress-circle mx-auto <?=$acpu>80?'cpu-high':($acpu>60?'cpu-medium':'cpu-low')?>" style="--size:100px;--value:<?=$acpu?>;" data-value="<?=$acpu?>"></div><div class="label mt-2">Avg CPU</div></div></div>
            <div class="col-md-3"><div class="card text-center p-3"><div class="progress-circle mx-auto" style="--size:100px;--value:<?=$amem?>;--fg-color:<?=$amem>80?'#dc3545':($amem>60?'#ffc107':'#0d6efd')?>;" data-value="<?=$amem?>"></div><div class="label mt-2">Avg Memory</div></div></div>
            <div class="col-md-3"><div class="card text-center p-3"><div class="progress-circle mx-auto" style="--size:100px;--value:<?=$adisk?>;--fg-color:<?=$adisk>80?'#dc3545':($adisk>60?'#ffc107':'#fd7e14')?>;" data-value="<?=$adisk?>"></div><div class="label mt-2">Avg Disk</div></div></div>
            <div class="col-md-3"><div class="card text-center p-3"><div class="progress-circle mx-auto" style="--size:100px;--value:<?=$sr?>;--fg-color:<?=$sr>90?'#28a745':($sr>70?'#ffc107':'#dc3545')?>;" data-value="<?=$sr?>"></div><div class="label mt-2">SNMP Success</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6"><div class="card p-3"><h6><i class="fas fa-chart-line me-2"></i>Network Bandwidth</h6><div class="row text-center"><div class="col-6"><div class="network-stats"><div class="label">INBOUND</div><div class="network-stat-value traffic-in"><?=formatBits($tin)?></div></div></div><div class="col-6"><div class="network-stats"><div class="label">OUTBOUND</div><div class="network-stat-value traffic-out"><?=formatBits($tout)?></div></div></div></div><?php if(!$tin&&!$tout):?><div class="text-warning small mt-2"><i class="fas fa-info-circle"></i> Bandwidth data will appear after SNMP collection.</div><?php endif;?></div></div>
            <div class="col-md-6"><div class="card p-3"><h6><i class="fas fa-chart-bar me-2"></i>Infrastructure</h6><div class="row text-center"><div class="col-4"><div class="network-stats"><div class="label">Devices</div><div class="network-stat-value"><?=$td?></div></div></div><div class="col-4"><div class="network-stats"><div class="label">SNMP OK</div><div class="network-stat-value"><?=$sw?></div></div></div><div class="col-4"><div class="network-stats"><div class="label">Interfaces</div><div class="network-stat-value"><?=$tif?></div></div></div></div></div></div>
        </div>
        <!-- Per-device cards -->
        <div class="row g-3">
        <?php foreach ($devs as $d):
            $did = $d['id'];
            $ifs = $ifstats[$did] ?? [];
            $cpu = $d['cpu_usage']??0;
            $cpuCls = $cpu>80?'cpu-high':($cpu>60?'cpu-medium':'cpu-low');
            $mt = $d['memory_total']??0; $mu = $d['memory_used']??0;
            $mp = $mt>0 ? round(($mu/$mt)*100,1) : 0;
            $dt = $d['disk_total']??0; $du = $d['disk_used']??0;
            $dp = $dt>0 ? round(($du/$dt)*100,1) : 0;
            $din = $dout = 0;
            foreach ($ifs as $if) { $din += $if['traffic_in_bps']??0; $dout += $if['traffic_out_bps']??0; }
            $last = $d['last_snmp_check'] ? date('Y-m-d H:i:s', strtotime($d['last_snmp_check'])) : 'N/A';
        ?>
            <div class="col-md-6">
                <div class="card p-3">
                    <h5><?=htmlspecialchars($d['name'])?></h5>
                    <small><?=$d['ip']?> &bull; <?=$d['type']?>/<?=$d['model']?></small>
                    <div class="row mt-3 text-center">
                        <div class="col-4"><div class="progress-circle mx-auto <?=$cpuCls?>" style="--value:<?=$cpu?>;" data-value="<?=$cpu?>"></div><small>CPU</small></div>
                        <div class="col-4"><div class="progress-circle mx-auto" style="--value:<?=$mp?>;--fg-color:<?=$mp>80?'#dc3545':($mp>60?'#ffc107':'#0d6efd')?>;" data-value="<?=$mp?>"></div><small>MEM</small></div>
                        <div class="col-4"><div class="progress-circle mx-auto" style="--value:<?=$dp?>;--fg-color:<?=$dp>80?'#dc3545':($dp>60?'#ffc107':'#fd7e14')?>;" data-value="<?=$dp?>"></div><small>DISK</small></div>
                    </div>
                    <div class="mt-2">
                        <div><i data-lucide="memory" class="icon-lucide"></i> Memory: <?=$mt?formatBytes($mu).'/'.formatBytes($mt)." ($mp%)":'<span class="text-warning">No data &mdash; device may not expose memory MIB</span>'?></div>
                        <div><i data-lucide="hdd" class="icon-lucide"></i> Disk: <?=$dt?formatBytes($du).'/'.formatBytes($dt)." ($dp%)":'<span class="text-warning">No data &mdash; device may not expose disk MIB</span>'?></div>
                        <div>
                            <i data-lucide="network" class="icon-lucide"></i> BW:
                            <?php if ($din > 0 || $dout > 0): ?>
                                <span class="traffic-in"><?=formatBits($din)?></span> IN /
                                <span class="traffic-out"><?=formatBits($dout)?></span> OUT
                            <?php elseif (isset($firstRunDevices[$did])): ?>
                                <span class="badge bg-warning text-dark"><i data-lucide="loader" class="icon-lucide" style="width:12px;height:12px;"></i> Collecting baseline&hellip;</span>
                                <small class="text-muted ms-1">(cumulative bytes available &mdash; BPS after next poll)</small>
                            <?php else: ?>
                                <span class="traffic-in">0 bps</span> IN / <span class="traffic-out">0 bps</span> OUT
                            <?php endif; ?>
                        </div>
                        <div><i data-lucide="clock" class="icon-lucide"></i> Last SNMP: <?=$last?></div>
                    </div>
                    <?php if ($ifs): ?>
                    <div class="accordion mt-2" id="acc<?=$did?>">
                        <div class="accordion-item border-0">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#coll<?=$did?>">Interfaces (<?=count($ifs)?>)</button></h2>
                            <div id="coll<?=$did?>" class="accordion-collapse collapse">
                                <div class="accordion-body p-0"><table class="table table-sm">
                                    <thead><tr><th>Name</th><th>Cumulative IN</th><th>Cumulative OUT</th><th>BPS IN</th><th>BPS OUT</th></tr></thead>
                                    <tbody>
                                <?php foreach ($ifs as $if):
                                    $in    = $if['traffic_in_bps']??0;
                                    $out   = $if['traffic_out_bps']??0;
                                    $sibIn  = $if['si_bytes_in']  ?? $if['bytes_in']  ?? 0;
                                    $sibOut = $if['si_bytes_out'] ?? $if['bytes_out'] ?? 0;
                                    $isFirstRun = ($in == 0 && $out == 0 && ($sibIn > 0 || $sibOut > 0));
                                ?>
                                <tr>
                                    <td><?=htmlspecialchars($if['if_name']??'?')?></td>
                                    <td><?=formatBytes($sibIn)?></td>
                                    <td><?=formatBytes($sibOut)?></td>
                                    <td class="traffic-in"><?=$isFirstRun ? '<span class="text-warning small">collecting&hellip;</span>' : formatBits($in)?></td>
                                    <td class="traffic-out"><?=$isFirstRun ? '<span class="text-warning small">collecting&hellip;</span>' : formatBits($out)?></td>
                                </tr>
                                <?php endforeach; ?>
                                    </tbody>
                                </table></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const s=document.getElementById('sidebar'),c=document.getElementById('main-content');
    function adj(){c.style.marginLeft=s&&s.classList.contains('collapsed')?'80px':'260px';}
    adj();
    if(s)new MutationObserver(adj).observe(s,{attributes:true,attributeFilter:['class']});
});
</script>
</body>
</html>
