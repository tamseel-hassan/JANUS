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

/* Interface traffic */
$ifstats = []; $ids = array_column($devs,'id');
if ($ids) {
    $ids = implode(',',array_map('intval',$ids));
    $iq = "
        SELECT it.device_id, it.if_index, it.if_name,
               it.bytes_in, it.bytes_out, it.traffic_in_bps, it.traffic_out_bps,
               it.checked_at, d.name device_name, d.ip device_ip,
               si.if_speed, si.if_descr
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

/* Totals */
$td = count($devs); $sw=0; $tcpu=$tmem=$tdisk=0; $tin=$tout=0; $tif=0;
foreach ($devs as $d) {
    if ($d['cpu_usage'] !== null) {
        $sw++; $tcpu += (float)$d['cpu_usage'];
        if ($d['memory_total']>0) $tmem += ($d['memory_used']/$d['memory_total'])*100;
        if ($d['disk_total']>0)   $tdisk += ($d['disk_used']/$d['disk_total'])*100;
    }
    if (isset($ifstats[$d['id']])) {
        foreach ($ifstats[$d['id']] as $if) {
            $tin += $if['traffic_in_bps']??0;
            $tout += $if['traffic_out_bps']??0;
            $tif++;
        }
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
    <link rel="stylesheet" href="css/pages/resources.css">
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content" style="padding:80px 24px 32px;margin-left:260px;">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i data-lucide="network" class="icon-lucide"></i> Resource Utilization</h2>
                <p class="lead">Real-time monitoring of device performance</p>
            </div>
        </div>
        <?php if (!empty($dl)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card p-3">
                    <label class="form-label"><i data-lucide="filter" class="icon-lucide me-2"></i>Filter by Device</label>
                    <div class="dropdown d-inline-block">
                        <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i data-lucide="server" class="icon-lucide me-2"></i>
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
            <i data-lucide="server" class="icon-lucide fa-3x text-muted mb-3"></i>
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
            <div class="col-md-6"><div class="card p-3"><h6><i data-lucide="line-chart" class="icon-lucide me-2"></i>Network Bandwidth</h6><div class="row text-center"><div class="col-6"><div class="network-stats"><div class="label">INBOUND</div><div class="network-stat-value traffic-in"><?=formatBits($tin)?></div></div></div><div class="col-6"><div class="network-stats"><div class="label">OUTBOUND</div><div class="network-stat-value traffic-out"><?=formatBits($tout)?></div></div></div></div><?php if(!$tin&&!$tout):?><div class="text-warning small mt-2"><i data-lucide="info" class="icon-lucide"></i> Bandwidth data will appear after SNMP collection.</div><?php endif;?></div></div>
            <div class="col-md-6"><div class="card p-3"><h6><i data-lucide="chart-bar" class="icon-lucide me-2"></i>Infrastructure</h6><div class="row text-center"><div class="col-4"><div class="network-stats"><div class="label">Devices</div><div class="network-stat-value"><?=$td?></div></div></div><div class="col-4"><div class="network-stats"><div class="label">SNMP OK</div><div class="network-stat-value"><?=$sw?></div></div></div><div class="col-4"><div class="network-stats"><div class="label">Interfaces</div><div class="network-stat-value"><?=$tif?></div></div></div></div></div></div>
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
                        <div><i data-lucide="memory" class="icon-lucide"></i> Memory: <?=$mt?formatBytes($mu).'/'.formatBytes($mt)." ($mp%)":'No data'?></div>
                        <div><i data-lucide="hdd" class="icon-lucide"></i> Disk: <?=$dt?formatBytes($du).'/'.formatBytes($dt)." ($dp%)":'No data'?></div>
                        <div><i data-lucide="network" class="icon-lucide"></i> BW: <span class="traffic-in"><?=formatBits($din)?></span> IN / <span class="traffic-out"><?=formatBits($dout)?></span> OUT</div>
                        <div><i data-lucide="clock" class="icon-lucide"></i> Last SNMP: <?=$last?></div>
                    </div>
                    <?php if ($ifs): ?>
                    <div class="accordion mt-2" id="acc<?=$did?>">
                        <div class="accordion-item border-0">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#coll<?=$did?>">Interfaces (<?=count($ifs)?>)</button></h2>
                            <div id="coll<?=$did?>" class="accordion-collapse collapse">
                                <div class="accordion-body p-0"><table class="table table-sm"><thead><tr><th>Name</th><th>IN</th><th>OUT</th></tr></thead><tbody>
                                <?php foreach ($ifs as $if): $in=$if['traffic_in_bps']??0; $out=$if['traffic_out_bps']??0; ?>
                                <tr><td><?=htmlspecialchars($if['if_name']??'?')?></td><td class="traffic-in"><?=formatBits($in)?></td><td class="traffic-out"><?=formatBits($out)?></td></tr>
                                <?php endforeach; ?></tbody></table></div>
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
