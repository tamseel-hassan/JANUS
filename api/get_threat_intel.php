<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB Error']);
    exit;
}

// Check if it's a POST request for analysis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $type = mysqli_real_escape_string($con, $data['observable_type'] ?? '');
    $value = mysqli_real_escape_string($con, trim($data['observable_value'] ?? ''));
    $incident_id = !empty($data['incident_id']) ? (int)$data['incident_id'] : null;
    
    if (empty($value)) {
        echo json_encode(['error' => 'No observable value provided']);
        exit;
    }

    $results = [];
    $message = '';

    // Check cache first
    $cache_check = mysqli_query($con, "
        SELECT * FROM threat_intel_cache 
        WHERE observable_type = '$type' 
        AND observable_value = '$value'
        AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY checked_at DESC
    ");
    
    if (mysqli_num_rows($cache_check) > 0) {
        while ($row = mysqli_fetch_assoc($cache_check)) {
            $results[$row['source']] = json_decode($row['result_data'], true);
            $results[$row['source']]['cached'] = true;
            $results[$row['source']]['risk_score'] = $row['risk_score'];
            $results[$row['source']]['is_malicious'] = $row['is_malicious'];
        }
        $message = 'Results loaded from cache';
    } else {
        // Perform fresh analysis via existing API
        $api_url = "http://localhost/JANUS_New/JANUS/api/threat_check.php";
        $post_data = http_build_query(['type' => $type, 'value' => $value]);
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $post_data
            ]
        ];
        
        $context = stream_context_create($options);
        $response = @file_get_contents($api_url, false, $context);
        
        if ($response) {
            $results = json_decode($response, true);
            $message = 'Fresh analysis completed';
        } else {
            echo json_encode(['error' => 'API analysis failed']);
            exit;
        }
    }
    
    // Log to history
    if (!empty($results)) {
        $risk_level = 'unknown';
        $avg_risk = 0;
        $count = 0;
        
        foreach ($results as $source => $resData) {
            if (isset($resData['risk_score'])) {
                $avg_risk += $resData['risk_score'];
                $count++;
            }
        }
        
        if ($count > 0) {
            $avg_risk = round($avg_risk / $count);
            if ($avg_risk >= 75) $risk_level = 'critical';
            elseif ($avg_risk >= 50) $risk_level = 'high';
            elseif ($avg_risk >= 25) $risk_level = 'medium';
            else $risk_level = 'low';
        }
        
        $result_summary = json_encode($results);
        $stmt = $con->prepare("
            INSERT INTO analysis_history 
            (user_id, incident_id, observable_type, observable_value, analysis_type, result_summary, risk_level)
            VALUES (?, ?, ?, ?, 'threat_intel', ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param('iissss', $_SESSION['id'], $incident_id, $type, $value, $result_summary, $risk_level);
            $stmt->execute();
            $stmt->close();
        }
    }

    echo json_encode(['message' => $message, 'results' => $results]);
    exit;
}

// GET request: Fetch history
$history = [];
$res = mysqli_query($con, "
    SELECT ah.*, a.username 
    FROM analysis_history ah
    LEFT JOIN accounts a ON ah.user_id = a.id
    WHERE ah.analysis_type = 'threat_intel'
    ORDER BY ah.created_at DESC
    LIMIT 100
");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $history[] = $r;
    }
}

echo json_encode(['history' => $history]);
