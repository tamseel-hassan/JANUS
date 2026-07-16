<?php
/**
 * modules/responder/auto_respond.php - Cron: evaluates rules against recent logs
 * Run via: php auto_respond.php  (or cron every 5 min)
 */
require_once __DIR__ . '/../../db_config.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    fwrite(STDERR, "DB connection failed: " . mysqli_connect_error() . "\n");
    exit(1);
}

$rules = mysqli_query($con, "SELECT * FROM responder_rules WHERE is_active = 1");
if (!$rules || mysqli_num_rows($rules) === 0) {
    echo "No active rules.\n";
    exit(0);
}

$evaluated = 0;
$triggered = 0;

while ($rule = mysqli_fetch_assoc($rules)) {
    $config = json_decode($rule['trigger_config'], true);
    $action = json_decode($rule['action_config'], true);
    $cooldown = intval($rule['cooldown_minutes'] ?? 60);

    // Check cooldown
    if ($rule['last_triggered_at']) {
        $last = strtotime($rule['last_triggered_at']);
        if (time() - $last < $cooldown * 60) {
            continue;
        }
    }

    $evaluated++;

    switch ($rule['trigger_type']) {
        case 'threshold':
            $window = intval($config['window_minutes'] ?? 5);
            $threshold = intval($config['threshold'] ?? 10);
            $pattern = mysqli_real_escape_string($con, $config['message_pattern'] ?? '');

            $sql = "
                SELECT src_ip, COUNT(*) AS cnt
                FROM (
                    SELECT src_ip, message FROM syslog_entries
                    WHERE received_at >= DATE_SUB(NOW(), INTERVAL {$window} MINUTE)
                      AND src_ip IS NOT NULL
                      AND message REGEXP '{$pattern}'
                    UNION ALL
                    SELECT src_ip, message FROM syslog_entries_archive
                    WHERE received_at >= DATE_SUB(NOW(), INTERVAL {$window} MINUTE)
                      AND src_ip IS NOT NULL
                      AND message REGEXP '{$pattern}'
                ) AS combined
                GROUP BY src_ip
                HAVING cnt >= {$threshold}
                ORDER BY cnt DESC
                LIMIT 10
            ";

            $result = mysqli_query($con, $sql);
            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $ip = $row['src_ip'];
                    $count = intval($row['cnt']);
                    executeAction($con, $rule, $ip, $count);
                    $triggered++;
                }
            }
            break;

        case 'keyword':
            $keywords = $config['keywords'] ?? [];
            if (empty($keywords)) break;

            $kw_pattern = implode('|', array_map(function($k) use ($con) {
                return mysqli_real_escape_string($con, $k);
            }, $keywords));

            $sql = "
                SELECT src_ip, COUNT(*) AS cnt
                FROM (
                    SELECT src_ip, message FROM syslog_entries
                    WHERE received_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                      AND src_ip IS NOT NULL
                      AND (message REGEXP '{$kw_pattern}')
                    UNION ALL
                    SELECT src_ip, message FROM syslog_entries_archive
                    WHERE received_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                      AND src_ip IS NOT NULL
                      AND (message REGEXP '{$kw_pattern}')
                ) AS combined
                GROUP BY src_ip
                HAVING cnt >= 1
                LIMIT 10
            ";

            $result = mysqli_query($con, $sql);
            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    executeAction($con, $rule, $row['src_ip'], intval($row['cnt']));
                    $triggered++;
                }
            }
            break;
    }

    // Update last_triggered_at
    if ($triggered > 0) {
        mysqli_query($con, "UPDATE responder_rules SET last_triggered_at = NOW() WHERE id = " . intval($rule['id']));
    }
}

mysqli_close($con);
echo "Evaluated $evaluated rules, triggered $triggered actions.\n";

function executeAction($con, $rule, $ip, $count) {
    $action = json_decode($rule['action_config'], true);
    $type = $rule['action_type'];

    $reason = "Auto-response: {$rule['name']} ({$count} matches from $ip)";

    // Block IP
    if ($type === 'block_ip' || $type === 'both') {
        $duration = intval($action['duration'] ?? 3600);
        $expires = date('Y-m-d H:i:s', time() + $duration);

        $stmt = $con->prepare(
            "INSERT IGNORE INTO responder_blocked_ips (ip_address, reason, source, block_method, expires_at, blocked_by)
             VALUES (?, ?, 'auto_responder', 'policy', ?, 0)"
        );
        $stmt->bind_param('sss', $ip, $reason, $expires);
        $stmt->execute();
        $stmt->close();

        echo "  BLOCKED: $ip ($reason)\n";
    }

    // Create Incident
    if ($type === 'create_incident' || $type === 'both') {
        $severity = $action['severity'] ?? 'medium';
        $due_days = ['low' => 14, 'medium' => 7, 'high' => 3, 'critical' => 1];
        $due_date = date('Y-m-d H:i:s', strtotime('+' . ($due_days[$severity] ?? 7) . ' days'));

        $title = "Auto-Detection: {$rule['name']} — $ip ($count matches)";
        $stmt = $con->prepare(
            "INSERT INTO incidents (title, description, type, severity, reported_by, due_date, status, source_ip)
             VALUES (?, ?, 'auto_response', ?, 0, ?, 'open', ?)"
        );
        $stmt->bind_param('sssss', $title, $reason, $severity, $due_date, $ip);
        $stmt->execute();
        $incident_id = $stmt->insert_id;
        $stmt->close();

        if ($incident_id) {
            $con->query("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value)
                         VALUES ($incident_id, 0, 'status', 'none', 'open')");
            echo "  INCIDENT: #$incident_id created for $ip\n";
        }
    }

    // Log execution
    $con->query("INSERT INTO responder_executions (playbook_id, status, step_results)
        VALUES ({$rule['id']}, 'auto_triggered', '" .
        mysqli_real_escape_string($con, json_encode(['ip' => $ip, 'count' => $count, 'rule' => $rule['name']])) .
        "')");
}
