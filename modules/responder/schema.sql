CREATE TABLE IF NOT EXISTS `responder_playbooks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `trigger_type` VARCHAR(50) NOT NULL DEFAULT 'manual',
  `enabled` TINYINT(1) DEFAULT 1,
  `priority` INT DEFAULT 10,
  `timeout_seconds` INT DEFAULT 60,
  `retry_count` INT DEFAULT 0,
  `actions` TEXT NOT NULL,
  `created_by` INT DEFAULT NULL,
  `last_executed_at` DATETIME DEFAULT NULL,
  `last_execution_status` VARCHAR(50) DEFAULT NULL,
  `execution_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `responder_executions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `playbook_id` INT NOT NULL,
  `incident_id` INT DEFAULT NULL,
  `triggered_by` VARCHAR(50) DEFAULT 'manual',
  `trigger_user_id` INT DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'pending',
  `input_data` TEXT,
  `step_results` TEXT,
  `total_steps` INT DEFAULT 0,
  `current_step` INT DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `responder_blocked_ips` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `reason` TEXT,
  `source` VARCHAR(50) DEFAULT 'manual',
  `block_method` VARCHAR(50) DEFAULT 'firewall',
  `expires_at` DATETIME DEFAULT NULL,
  `blocked_by` INT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `responder_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `trigger_type` VARCHAR(50) NOT NULL,
  `trigger_config` TEXT,
  `action_type` VARCHAR(50) NOT NULL,
  `action_config` TEXT,
  `cooldown_minutes` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_triggered` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `responder_webhooks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `endpoint_key` VARCHAR(255) NOT NULL UNIQUE,
  `auto_create_incident` TINYINT(1) DEFAULT 1,
  `enabled` TINYINT(1) DEFAULT 1,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
