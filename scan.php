<?php
// vuln_scan.php - Professional Nmap Integration for Janus
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

// Increase execution time for scans (Nmap takes time)
set_time_limit(300); 

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$theme = $_COOKIE['theme'] ?? 'dark';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <title>Advanced Threat Scanner</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/theme.css">
    <style>
        .scanner-header {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.95));
            border-bottom: 1px solid var(--accent);
            padding: 2.5rem 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        .scanner-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 50% 50%, var(--accent) 0%, transparent 70%);
            opacity: 0.1;
            pointer-events: none;
        }
        .terminal-window {
            background: #0f172a;
            border-radius: 8px;
            border: 1px solid #334155;
            font-family: 'Consolas', 'Monaco', monospace;
            padding: 1rem;
            color: #22d3ee;
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }
        .service-row { transition: background 0.2s; }
        .service-row:hover { background: rgba(255,255,255,0.03); }
        
        .badge-critical { background: #ef4444; color: white; }
        .badge-high { background: #f97316; color: white; }
        .badge-medium { background: #eab308; color: black; }
        .badge-low { background: #22c55e; color: white; }

        /* Loading Animation */
        .scan-loader {
            display: none;
            text-align: center;
            padding: 3rem;
        }
        .radar {
            width: 60px; height: 60px;
            border-radius: 50%;
            border: 2px solid var(--accent);
            margin: 0 auto 1rem;
            position: relative;
            animation: pulse 2s infinite;
        }
        .radar::after {
            content: ''; position: absolute;
            top: 50%; left: 50%;
            width: 100%; height: 2px;
            background: var(--accent);
            transform-origin: 0 0;
            animation: sweep 1.5s infinite linear;
        }
        @keyframes sweep { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(96, 165, 250, 0.4); } 70% { box-shadow: 0 0 0 20px rgba(96, 165, 250, 0); } 100% { box-shadow: 0 0 0 0 rgba(96, 165, 250, 0); } }
    </style>
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
    <div class="scanner-header text-center">
        <h2 class="mb-2"><i class="fas fa-satellite-dish"></i> Deep Packet Inspection Scanner</h2>
        <p class="text-muted">Janusscanning Engine | Service Version Detection | OS Fingerprinting</p>
    </div>

    <div class="container-fluid">
        
        <div class="card mb-4" style="max-width: 800px; margin: 0 auto;">
            <div class="card-body p-4">
                <form method="post" id="scanForm" onsubmit="showLoader()">
                    <label class="form-label text-muted">Target Host / IP Address</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-transparent border-secondary text-primary">
                            <i class="fas fa-network-wired"></i>
                        </span>
                        <input type="text" name="target" class="form-control bg-transparent text-light border-secondary" 
                               placeholder="e.g. 192.168.1.10 or example.com" 
                               value="<?= isset($_POST['target']) ? htmlspecialchars($_POST['target']) : '' ?>" required>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">
                            <i class="fas fa-search"></i> INITIATE SCAN
                        </button>
                    </div>
                    <div class="form-text mt-2">
                        <i class="fas fa-info-circle"></i> This scan performs active service fingerprinting (`-sV`). Please allow 15-45 seconds.
                    </div>
                </form>
            </div>
        </div>

        <div id="scanLoader" class="scan-loader">
            <div class="radar"></div>
            <h4 class="mt-3">Analyzing Network Topology...</h4>
            <p class="text-muted">Performing TCP handshake and banner grabbing</p>
        </div>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['target'])) {
            $target = trim($_POST['target']);

            // 1. Validation
            if (!filter_var($target, FILTER_VALIDATE_IP) && !filter_var($target, FILTER_VALIDATE_DOMAIN)) {
                echo '<div class="alert alert-danger text-center">Invalid Target Format. Please use a valid IP or Domain.</div>';
            } 
            // 2. Check for Nmap
            elseif (!shell_exec('which nmap')) {
                echo '<div class="alert alert-warning text-center">
                        <strong>Configuration Error:</strong> Nmap is not installed on this server.<br>
                        Run <code>sudo apt install nmap</code> in the terminal.
                      </div>';
            }
            else {
                // 3. Execution
                // -sV: Service Version
                // --open: Only show open ports
                // -T4: Faster timing
                // -oX -: Output XML to stdout
                // escapeshellarg: Security crucial to prevent command injection
                $cmd = "nmap -sV --open -T4 -oX - " . escapeshellarg($target);
                
                // Execute and capture XML output
                $xmlOutput = shell_exec($cmd);

                // 4. Parsing
                if ($xmlOutput && strpos($xmlOutput, '<?xml') !== false) {
                    try {
                        $xml = new SimpleXMLElement($xmlOutput);
                        
                        // Extract Host Info
                        $status = (string)$xml->host->status['state']; // up or down
                        $addr   = (string)$xml->host->address['addr'];
                        $hostName = (string)$xml->host->hostnames->hostname['name'] ?? 'Unknown DNS';
                        
                        if ($status !== 'up') {
                            echo '<div class="alert alert-secondary text-center">Host appears DOWN or is blocking ping probes.</div>';
                        } else {
                            // Calculate Risk Score based on results
                            $ports = $xml->host->ports->port;
                            $openPortCount = count($ports);
                            
                            echo '<div class="row mb-4">';
                            echo '<div class="col-md-4">
                                    <div class="card h-100 border-primary">
                                        <div class="card-body text-center">
                                            <h6 class="text-muted text-uppercase">Target Status</h6>
                                            <h3 class="text-success"><i class="fas fa-check-circle"></i> ONLINE</h3>
                                            <p class="mb-0">'.$addr.'</p>
                                            <small class="text-muted">'.$hostName.'</small>
                                        </div>
                                    </div>
                                  </div>';
                            echo '<div class="col-md-4">
                                    <div class="card h-100 border-warning">
                                        <div class="card-body text-center">
                                            <h6 class="text-muted text-uppercase">Open Ports</h6>
                                            <h3 class="text-white">'.$openPortCount.'</h3>
                                            <p class="mb-0">Services Detected</p>
                                        </div>
                                    </div>
                                  </div>';
                            echo '<div class="col-md-4">
                                    <div class="card h-100 border-info">
                                        <div class="card-body text-center">
                                            <h6 class="text-muted text-uppercase">Scan Type</h6>
                                            <h3 class="text-info">INTENSE</h3>
                                            <p class="mb-0">Version Detection</p>
                                        </div>
                                    </div>
                                  </div>';
                            echo '</div>'; // End Row

                            echo '<div class="card">';
                            echo '<div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-list-ul"></i> Detected Services</h5>
                                    <span class="badge bg-dark">'.$addr.'</span>
                                  </div>';
                            echo '<div class="table-responsive">';
                            echo '<table class="table table-hover align-middle mb-0">';
                            echo '<thead class="table-dark"><tr>
                                    <th>Port</th>
                                    <th>Protocol</th>
                                    <th>State</th>
                                    <th>Service</th>
                                    <th>Version / Product</th>
                                    <th>Risk Assessment</th>
                                  </tr></thead><tbody>';

                            foreach ($ports as $port) {
                                $portID   = (string)$port['portid'];
                                $protocol = (string)$port['protocol'];
                                $state    = (string)$port->state['state'];
                                $serviceName = (string)$port->service['name'];
                                $product  = (string)$port->service['product'];
                                $version  = (string)$port->service['version'];
                                $extra    = (string)$port->service['extrainfo'];
                                
                                // Determine Risk Logic based on Port/Service
                                $riskBadge = '<span class="badge badge-low">Low</span>';
                                $desc = 'Standard service';
                                
                                // Simple heuristic for risk visualization
                                if (in_array($portID, ['21','23','445','3389'])) {
                                    $riskBadge = '<span class="badge badge-critical">CRITICAL</span>';
                                    $desc = 'High-value target protocol';
                                } elseif (!empty($version) && (strpos($version, '1.') === 0 || strpos($version, 'old') !== false)) {
                                    $riskBadge = '<span class="badge badge-high">High</span>';
                                    $desc = 'Potentially outdated version';
                                } elseif ($portID == '80' || $portID == '443' || $portID == '8080') {
                                    $riskBadge = '<span class="badge badge-medium">Medium</span>';
                                    $desc = 'Web Service - Check for app vulns';
                                }

                                echo '<tr class="service-row">';
                                echo '<td><span class="fw-bold text-info">'.$portID.'</span></td>';
                                echo '<td>'.strtoupper($protocol).'</td>';
                                echo '<td><span class="badge bg-success">'.$state.'</span></td>';
                                echo '<td class="fw-bold">'.ucfirst($serviceName).'</td>';
                                echo '<td>';
                                echo $product ? '<span class="text-light">'.$product.'</span> ' : '';
                                echo $version ? '<span class="text-muted small">('.$version.')</span>' : '<span class="text-muted small">Unknown</span>';
                                echo '</td>';
                                echo '<td>'.$riskBadge.' <small class="d-block text-muted" style="font-size:0.75em">'.$desc.'</small></td>';
                                echo '</tr>';
                            }
                            echo '</tbody></table></div></div>';
                            
                            // Raw XML Debug (Optional - good for "Hacker" feel)
                            echo '<div class="mt-4">
                                    <p class="mb-1 text-muted"><small>RAW SCAN OUTPUT LOG</small></p>
                                    <div class="terminal-window">
                                        <div class="text-secondary">// Initiating Nmap 7.9x at '.date('H:i:s').'</div>
                                        <div class="text-secondary">// Command: '.$cmd.'</div>
                                        <br>
                                        '.htmlspecialchars($xmlOutput).'
                                    </div>
                                  </div>';
                        }
                    } catch (Exception $e) {
                        echo '<div class="alert alert-danger">Error parsing scan results. Ensure Nmap is functioning correctly.</div>';
                    }
                } else {
                    echo '<div class="alert alert-danger">Scan failed or timed out. Check if host is online and Nmap is installed.</div>';
                }
            }
        }
        ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function showLoader() {
        document.getElementById('scanForm').style.display = 'none';
        document.getElementById('scanLoader').style.display = 'block';
    }

    // Include Sidebar logic
    document.addEventListener('DOMContentLoaded', () => {
        const s = document.getElementById('sidebar');
        const c = document.getElementById('main-content');
        const adj = () => c.style.marginLeft = s?.classList.contains('collapsed') ? '80px' : '260px'; // Matched 260px from your CSS
        adj();
        if(s) new MutationObserver(adj).observe(s, { attributes: true, attributeFilter: ['class'] });
    });
</script>
</body>
</html>
