<?php
require_once __DIR__ . '/../../db_config.php';

class PlaybookEngine {
    private mysqli $con;

    public function __construct() {
        global $con;
        $this->con = $con;
    }

    public function execute(int $executionId): array {
        $stmt = $this->con->prepare(
            "SELECT pe.*, p.name, p.actions, p.timeout_seconds, p.retry_count
             FROM responder_executions pe
             JOIN responder_playbooks p ON pe.playbook_id = p.id
             WHERE pe.id = ?"
        );
        $stmt->bind_param('i', $executionId);
        $stmt->execute();
        $execution = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$execution) {
            return ['status' => 'failed', 'error' => 'Execution not found'];
        }

        $this->updateExecution($executionId, ['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);

        $actions = json_decode($execution['actions'] ?? '[]', true);
        $totalSteps = count($actions);
        $this->updateExecution($executionId, ['total_steps' => $totalSteps]);

        $stepResults = [];
        foreach ($actions as $index => $action) {
            $this->updateExecution($executionId, ['current_step' => $index + 1]);

            $result = $this->executeAction($action, $execution);
            $stepResults[] = [
                'step' => $index + 1,
                'action' => $action['type'] ?? 'unknown',
                'result' => $result,
            ];

            if (!$result['success'] && ($action['on_error'] ?? 'continue') === 'stop') {
                $this->updateExecution($executionId, [
                    'status' => 'failed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'step_results' => json_encode($stepResults),
                    'error_message' => $result['error'] ?? 'Action failed',
                ]);
                return ['status' => 'failed', 'step' => $index + 1];
            }
        }

        $this->updateExecution($executionId, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'step_results' => json_encode($stepResults),
        ]);

        $this->con->query(
            "UPDATE responder_playbooks SET
             last_executed_at = NOW(),
             last_execution_status = 'completed',
             execution_count = execution_count + 1
             WHERE id = " . intval($execution['playbook_id'])
        );

        return ['status' => 'completed', 'steps' => $totalSteps];
    }

    private function executeAction(array $action, array $execution): array {
        return match ($action['type'] ?? '') {
            'block_ip'         => $this->actionBlockIp($action),
            'unblock_ip'       => $this->actionUnblockIp($action),
            'create_incident'  => $this->actionCreateIncident($action),
            'update_incident'  => $this->actionUpdateIncident($action),
            'send_notification' => $this->actionSendNotification($action),
            'send_webhook'     => $this->actionSendWebhook($action),
            'send_email'       => $this->actionSendEmail($action),
            'delay'            => $this->actionDelay($action),
            default            => ['success' => false, 'error' => 'Unknown action: ' . ($action['type'] ?? '')],
        };
    }

    private function actionBlockIp(array $action): array {
        $ip = $action['ip'] ?? '';
        $reason = $action['reason'] ?? 'Playbook block';
        $duration = $action['duration'] ?? null;

        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Valid IP required'];
        }

        $stmt = $this->con->prepare(
            "INSERT INTO responder_blocked_ips (ip_address, reason, source, block_method, expires_at, blocked_by)
             VALUES (?, ?, 'playbook', 'firewall_policy', ?, ?)"
        );
        $expires = $duration ? date('Y-m-d H:i:s', time() + (int)$duration) : null;
        $blocked_by = intval($_SESSION['id'] ?? 0);
        $stmt->bind_param('sssi', $ip, $reason, $expires, $blocked_by);
        $stmt->execute();
        $stmt->close();

        return ['success' => true, 'blocked_ip' => $ip];
    }

    private function actionUnblockIp(array $action): array {
        $ip = $action['ip'] ?? '';
        if (empty($ip)) return ['success' => false, 'error' => 'IP required'];

        $this->con->query(
            "DELETE FROM responder_blocked_ips WHERE ip_address = '" . $this->con->real_escape_string($ip) . "'"
        );
        return ['success' => true];
    }

    private function actionCreateIncident(array $action): array {
        $title = $action['title'] ?? 'Playbook Generated Incident';
        $severity = $action['severity'] ?? 'medium';
        $source_ip = $action['source_ip'] ?? null;
        $reported_by = intval($_SESSION['id'] ?? 0);
        $due_days = ['low' => 14, 'medium' => 7, 'high' => 3, 'critical' => 1];
        $due_date = date('Y-m-d H:i:s', strtotime('+' . ($due_days[$severity] ?? 7) . ' days'));

        $stmt = $this->con->prepare(
            "INSERT INTO incidents (title, description, type, severity, reported_by, due_date, status, source_ip)
             VALUES (?, ?, 'automation', ?, ?, ?, 'open', ?)"
        );
        $stmt->bind_param('sssiss', $title, $action['description'] ?? '', $severity, $reported_by, $due_date, $source_ip);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        if ($id) {
            $hstmt = $this->con->prepare(
                "INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'status', 'none', 'open')"
            );
            $hstmt->bind_param('ii', $id, $reported_by);
            $hstmt->execute();
            $hstmt->close();
        }

        return ['success' => true, 'incident_id' => $id];
    }

    private function actionUpdateIncident(array $action): array {
        $incidentId = intval($action['incident_id'] ?? 0);
        $status = $action['status'] ?? null;
        if (!$incidentId) return ['success' => false, 'error' => 'incident_id required'];

        if ($status) {
            $stmt = $this->con->prepare("UPDATE incidents SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $status, $incidentId);
            $stmt->execute();
            $stmt->close();
        }
        return ['success' => true];
    }

    private function actionSendNotification(array $action): array {
        $message = $action['message'] ?? 'Playbook notification';
        $this->con->query(
            "INSERT INTO responder_executions (playbook_id, status, step_results)
             VALUES (0, 'notification', '" . $this->con->real_escape_string($message) . "')"
        );
        return ['success' => true];
    }

    private function actionSendWebhook(array $action): array {
        $url = $action['url'] ?? '';
        $payload = $action['payload'] ?? [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => $httpCode >= 200 && $httpCode < 300, 'http_code' => $httpCode];
    }

    private function actionSendEmail(array $action): array {
        return ['success' => true];
    }

    private function actionDelay(array $action): array {
        $seconds = min(intval($action['seconds'] ?? 0), 300);
        sleep($seconds);
        return ['success' => true, 'delayed_seconds' => $seconds];
    }

    private function updateExecution(int $id, array $data): void {
        $sets = [];
        foreach (array_keys($data) as $key) {
            $sets[] = "$key = ?";
        }
        $sql = "UPDATE responder_executions SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $this->con->prepare($sql);
        $types = '';
        $values = [];
        foreach ($data as $v) {
            if (is_int($v)) { $types .= 'i'; $values[] = $v; }
            elseif (is_null($v)) { $types .= 's'; $values[] = null; }
            else { $types .= 's'; $values[] = $v; }
        }
        $types .= 'i';
        $values[] = $id;
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }
}
