-- ============================================================
--  JANUS - Fresh Database Schema
--  Single database: alogin
--  Generated from source code analysis of all modules
--  NO legacy data - clean start
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ============================================================
-- 1. ACCOUNTS & AUTH
-- ============================================================
CREATE TABLE IF NOT EXISTS `accounts` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(50)  NOT NULL UNIQUE,
  `password`      VARCHAR(255) NOT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(100),
  `role`          ENUM('admin','analyst','operator') NOT NULL DEFAULT 'analyst',
  `photo`         VARCHAR(255) DEFAULT NULL,
  `last_login`    DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `is_active`     TINYINT(1)   DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin account (password: Admin@Janus2026 - CHANGE THIS)
INSERT IGNORE INTO `accounts` (`username`, `password`, `name`, `email`, `role`)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@janus.local', 'admin');

-- ============================================================
-- 2. SYSTEM CONFIG
-- ============================================================
CREATE TABLE IF NOT EXISTS `system_config` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `key`       VARCHAR(100) NOT NULL UNIQUE,
  `value`     TEXT,
  `config_key`  VARCHAR(100) GENERATED ALWAYS AS (`key`)   VIRTUAL,
  `config_value` TEXT        GENERATED ALWAYS AS (`value`) VIRTUAL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `system_config` (`key`, `value`) VALUES
('app_name',    'Janus'),
('app_version', '2.0.0'),
('timezone',    'Asia/Karachi'),
('ping_interval', '120'),
('snmp_timeout', '5'),
('syslog_port',  '514');

-- ============================================================
-- 3. CORE NOC - DEVICES & LINKS
-- ============================================================
CREATE TABLE IF NOT EXISTS `devices` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(100) NOT NULL,
  `ip`               VARCHAR(45)  NOT NULL UNIQUE,
  `type`             VARCHAR(50)  DEFAULT 'router',
  `model`            VARCHAR(100) DEFAULT NULL,
  `city`             VARCHAR(100) DEFAULT NULL,
  `sub_office`       VARCHAR(100) DEFAULT NULL,
  `country`          VARCHAR(100) DEFAULT NULL,
  `contact_number`   VARCHAR(50)  DEFAULT NULL,
  `email`            VARCHAR(100) DEFAULT NULL,
  `snmp_community`   VARCHAR(100) DEFAULT 'public',
  `snmp_version`     VARCHAR(5)   DEFAULT '2c',
  `snmp_port`        INT          DEFAULT 161,
  `icon_set`         VARCHAR(50)  DEFAULT NULL,
  `icon_size`        INT          DEFAULT 40,
  `created_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `links` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(100) NOT NULL,
  `ip`             VARCHAR(45)  DEFAULT NULL,
  `from_device_id` INT          DEFAULT NULL,
  `to_device_id`   INT          DEFAULT NULL,
  `created_at`     DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`from_device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`to_device_id`)   REFERENCES `devices`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. PING MONITORING
-- ============================================================
CREATE TABLE IF NOT EXISTS `ping_logs` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `device_id`  INT          DEFAULT NULL,
  `link_id`    INT          DEFAULT NULL,
  `status`     ENUM('up','down') NOT NULL,
  `rtt_avg`    FLOAT        DEFAULT NULL,
  `sent`       INT          DEFAULT NULL,
  `received`   INT          DEFAULT NULL,
  `loss_pct`   FLOAT        DEFAULT NULL,
  `checked_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_device_checked` (`device_id`, `checked_at`),
  INDEX `idx_link_checked`   (`link_id`,   `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ping_logs_archive` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `device_id`  INT          DEFAULT NULL,
  `link_id`    INT          DEFAULT NULL,
  `status`     ENUM('up','down') NOT NULL,
  `rtt_avg`    FLOAT        DEFAULT NULL,
  `sent`       INT          DEFAULT NULL,
  `received`   INT          DEFAULT NULL,
  `loss_pct`   FLOAT        DEFAULT NULL,
  `checked_at` DATETIME     DEFAULT NULL,
  INDEX `idx_arch_device` (`device_id`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `device_status_cache` (
  `device_id`          INT PRIMARY KEY,
  `status`             ENUM('up','down') NOT NULL,
  `rtt_avg`            FLOAT DEFAULT NULL,
  `checked_at`         DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_status_change` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_up`            DATETIME DEFAULT NULL,
  `last_down`          DATETIME DEFAULT NULL,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. TOPOLOGY MAPS
-- ============================================================
CREATE TABLE IF NOT EXISTS `topology_maps` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `map_device_visibility` (
  `map_id`     INT NOT NULL,
  `device_id`  INT NOT NULL,
  `is_visible` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`map_id`, `device_id`),
  FOREIGN KEY (`map_id`)    REFERENCES `topology_maps`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `device_positions` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `map_id`     INT NOT NULL,
  `device_id`  INT NOT NULL,
  `x`          FLOAT DEFAULT 0,
  `y`          FLOAT DEFAULT 0,
  `icon_set`   VARCHAR(50) DEFAULT NULL,
  `icon_size`  INT DEFAULT 36,
  `visible`    TINYINT(1) DEFAULT 1,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `map_device` (`map_id`, `device_id`),
  FOREIGN KEY (`map_id`)    REFERENCES `topology_maps`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. SNMP & PERFORMANCE
-- ============================================================
CREATE TABLE IF NOT EXISTS `snmp_metrics` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `device_id`    INT NOT NULL UNIQUE,
  `cpu_usage`    DOUBLE DEFAULT NULL,
  `memory_total` BIGINT DEFAULT NULL,
  `memory_used`  BIGINT DEFAULT NULL,
  `memory_free`  BIGINT DEFAULT NULL,
  `disk_total`   BIGINT DEFAULT NULL,
  `disk_used`    BIGINT DEFAULT NULL,
  `disk_free`    BIGINT DEFAULT NULL,
  `load_1min`    DOUBLE DEFAULT NULL,
  `load_5min`    DOUBLE DEFAULT NULL,
  `load_15min`   DOUBLE DEFAULT NULL,
  `network_in`   BIGINT DEFAULT 0,
  `network_out`  BIGINT DEFAULT 0,
  `processes`    INT DEFAULT NULL,
  `checked_at`   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `snmp_interfaces` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `device_id`  INT NOT NULL,
  `if_index`   INT NOT NULL,
  `if_descr`   VARCHAR(512) DEFAULT NULL,
  `if_speed`   BIGINT DEFAULT NULL,
  `bytes_in`   BIGINT DEFAULT NULL,
  `bytes_out`  BIGINT DEFAULT NULL,
  `checked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_dev_if` (`device_id`, `if_index`),
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `interface_traffic` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `device_id`       INT NOT NULL,
  `if_index`        INT NOT NULL,
  `if_name`         VARCHAR(255) DEFAULT NULL,
  `bytes_in`        BIGINT DEFAULT NULL,
  `bytes_out`       BIGINT DEFAULT NULL,
  `traffic_in_bps`  BIGINT DEFAULT NULL,
  `traffic_out_bps` BIGINT DEFAULT NULL,
  `checked_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_traffic_dev_if` (`device_id`, `if_index`, `checked_at`),
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. SYSLOG / SIEM
-- ============================================================
CREATE TABLE IF NOT EXISTS `syslog_sources` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `appliance_type` VARCHAR(100) NOT NULL,
  `source_ip`      VARCHAR(45)  NOT NULL UNIQUE,
  `hostname`       VARCHAR(255) DEFAULT NULL,
  `is_active`      TINYINT(1)   DEFAULT 1,
  `added_by`       INT          DEFAULT NULL,
  `added_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `created_at`     DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `syslog_entries` (
  `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
  `source_id`      INT         DEFAULT NULL,
  `appliance_type` VARCHAR(100) DEFAULT NULL,
  `source_ip`      VARCHAR(45) DEFAULT NULL,
  `facility`       INT         DEFAULT NULL,
  `severity`       INT         DEFAULT NULL,
  `message`        TEXT,
  `raw`            TEXT,
  `received_at`    DATETIME    DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_syslog_source`   (`source_id`),
  INDEX `idx_syslog_received` (`received_at`),
  INDEX `idx_syslog_severity` (`severity`),
  FOREIGN KEY (`source_id`) REFERENCES `syslog_sources`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `syslog_entries_archive` (
  `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
  `source_id`      INT         DEFAULT NULL,
  `source_ip`      VARCHAR(45) DEFAULT NULL,
  `appliance_type` VARCHAR(100) DEFAULT NULL,
  `facility`       INT         DEFAULT NULL,
  `severity`       INT         DEFAULT NULL,
  `message`        TEXT,
  `raw`            TEXT,
  `received_at`    DATETIME    DEFAULT NULL,
  INDEX `idx_arch_syslog_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. SOC - INCIDENTS & TICKETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `incidents` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `title`               VARCHAR(255) NOT NULL,
  `description`         TEXT,
  `severity`            ENUM('low','medium','high','critical') DEFAULT 'medium',
  `status`              ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
  `type`                VARCHAR(100) DEFAULT NULL,
  `assigned_to`         INT          DEFAULT NULL,
  `reported_by`         INT          DEFAULT NULL,
  `device_id`           INT          DEFAULT NULL,
  `due_date`            DATETIME     DEFAULT NULL,
  `resolved_at`         DATETIME     DEFAULT NULL,
  `unread_by_assignee`  TINYINT(1)   DEFAULT 1,
  `created_at`          DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_incident_status`   (`status`),
  INDEX `idx_incident_assigned` (`assigned_to`),
  FOREIGN KEY (`assigned_to`) REFERENCES `accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reported_by`) REFERENCES `accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `incident_comments` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `incident_id` INT  NOT NULL,
  `user_id`     INT  DEFAULT NULL,
  `comment`     TEXT NOT NULL,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)     REFERENCES `accounts`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `incident_history` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `incident_id` INT          NOT NULL,
  `changed_by`  INT          DEFAULT NULL,
  `field`       VARCHAR(100) DEFAULT NULL,
  `old_value`   TEXT,
  `new_value`   TEXT,
  `changed_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `event_comments` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `device_id`         INT NOT NULL,
  `event_start_time`  DATETIME NOT NULL,
  `event_end_time`    DATETIME DEFAULT NULL,
  `comments`          TEXT,
  `action_taken`      VARCHAR(255) DEFAULT NULL,
  `escalation_level`  INT DEFAULT 1,
  `vendor_contacted`  VARCHAR(255) DEFAULT NULL,
  `ticket_number`     VARCHAR(100) DEFAULT NULL,
  `resolution_time`   DATETIME DEFAULT NULL,
  `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_device_event` (`device_id`, `event_start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. IPAM
-- ============================================================
CREATE TABLE IF NOT EXISTS `ipam_subnets` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `cidr`         VARCHAR(43)  NOT NULL UNIQUE,
  `label`        VARCHAR(128) DEFAULT NULL,
  `vlan_id`      VARCHAR(32)  DEFAULT NULL,
  `description`  TEXT         DEFAULT NULL,
  `interface`    VARCHAR(32)  DEFAULT NULL,
  `scan_enabled` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ip_addresses` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `subnet_id`   INT          DEFAULT NULL,
  `ip`          VARCHAR(45)  NOT NULL UNIQUE,
  `hostname`    VARCHAR(255) DEFAULT NULL,
  `mac`         VARCHAR(20)  DEFAULT NULL,
  `status`      ENUM('active','inactive','reserved','unknown') DEFAULT 'unknown',
  `device_id`   INT          DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `last_seen`   DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`subnet_id`) REFERENCES `ipam_subnets`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ipam_nat_map` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `private_ip`   VARCHAR(45) NOT NULL,
  `public_ip`    VARCHAR(45) NOT NULL,
  `description`  VARCHAR(255) DEFAULT NULL,
  `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `janus_ipam` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `ip`          VARCHAR(45)  NOT NULL UNIQUE,
  `mac`         VARCHAR(20)  DEFAULT NULL,
  `assigned_to` VARCHAR(255) DEFAULT NULL,
  `status`      ENUM('active','inactive','reserved') NOT NULL DEFAULT 'inactive',
  `vlan`        VARCHAR(50)  DEFAULT NULL,
  `notes`       TEXT         DEFAULT NULL,
  `first_seen`  DATETIME     DEFAULT NULL,
  `last_seen`   DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `janus_ipam_log` (
  `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ip`              VARCHAR(45) DEFAULT NULL,
  `old_mac`         VARCHAR(20) DEFAULT NULL,
  `new_mac`         VARCHAR(20) DEFAULT NULL,
  `note`            VARCHAR(255) DEFAULT NULL,
  `acknowledged`    TINYINT(1) NOT NULL DEFAULT 0,
  `acknowledged_by` VARCHAR(128) DEFAULT NULL,
  `ack_note`        TEXT DEFAULT NULL,
  `acknowledged_at` DATETIME DEFAULT NULL,
  `changed_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_log_ip` (`ip`),
  INDEX `idx_log_acked` (`acknowledged`),
  INDEX `idx_log_ts` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. NOC - LAYER 2 / NAC
-- ============================================================
CREATE TABLE IF NOT EXISTS `nac_switches` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `ip`          VARCHAR(45)  NOT NULL UNIQUE,
  `community`   VARCHAR(100) DEFAULT 'public',
  `version`     VARCHAR(5)   DEFAULT '2c',
  `location`    VARCHAR(255) DEFAULT NULL,
  `model`       VARCHAR(100) DEFAULT NULL,
  `group_id`    INT          DEFAULT NULL,
  `ssh_port`    INT          DEFAULT 22,
  `username`    VARCHAR(100) DEFAULT NULL,
  `password`    VARCHAR(255) DEFAULT NULL,
  `enabled`     TINYINT(1)   DEFAULT 1,
  `platform`    VARCHAR(50)  DEFAULT NULL,
  `last_polled` DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_switch_groups` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `sort_order`  INT          DEFAULT 0,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_ports` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `switch_id`   INT          NOT NULL,
  `port_index`  INT          DEFAULT NULL,
  `port_name`   VARCHAR(100) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `oper_status` VARCHAR(20)  DEFAULT NULL,
  `admin_status` VARCHAR(20) DEFAULT NULL,
  `speed`       BIGINT       DEFAULT NULL,
  `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`switch_id`) REFERENCES `nac_switches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_port_macs` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `switch_id`  INT         NOT NULL,
  `port_id`    INT         DEFAULT NULL,
  `mac`        VARCHAR(20) NOT NULL,
  `vlan`       INT         DEFAULT NULL,
  `seen_at`    DATETIME    DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_nac_mac` (`mac`),
  FOREIGN KEY (`switch_id`) REFERENCES `nac_switches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_mac_ip_map` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `mac`        VARCHAR(20) NOT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `hostname`   VARCHAR(255) DEFAULT NULL,
  `last_seen`  DATETIME    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_mac` (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_mac_events` (
  `id`        BIGINT AUTO_INCREMENT PRIMARY KEY,
  `mac`       VARCHAR(20) NOT NULL,
  `ip`        VARCHAR(45) DEFAULT NULL,
  `switch_id` INT         DEFAULT NULL,
  `port_id`   INT         DEFAULT NULL,
  `event`     VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME   DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_nac_event_mac` (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_port_bw` (
  `id`           BIGINT AUTO_INCREMENT PRIMARY KEY,
  `port_id`      INT    NOT NULL,
  `in_octets`    BIGINT DEFAULT NULL,
  `out_octets`   BIGINT DEFAULT NULL,
  `collected_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_nac_bw_port` (`port_id`, `collected_at`),
  FOREIGN KEY (`port_id`) REFERENCES `nac_ports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_topology_links` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `from_switch` INT DEFAULT NULL,
  `to_switch`   INT DEFAULT NULL,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`from_switch`) REFERENCES `nac_switches`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`to_switch`)   REFERENCES `nac_switches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_platforms` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `driver`     VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 11. THREAT INTEL & SECURITY
-- ============================================================
CREATE TABLE IF NOT EXISTS `threat_intel_cache` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `ip`           VARCHAR(45)  NOT NULL UNIQUE,
  `score`        INT          DEFAULT NULL,
  `country`      VARCHAR(100) DEFAULT NULL,
  `isp`          VARCHAR(255) DEFAULT NULL,
  `is_malicious` TINYINT(1)   DEFAULT 0,
  `raw_data`     JSON         DEFAULT NULL,
  `checked_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   DATETIME     DEFAULT NULL,
  INDEX `idx_threat_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `observables` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `type`        VARCHAR(50)  NOT NULL,
  `value`       VARCHAR(255) NOT NULL,
  `incident_id` INT          DEFAULT NULL,
  `notes`       TEXT,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `scanner_records` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `target_ip`   VARCHAR(45)  NOT NULL,
  `scan_type`   VARCHAR(50)  DEFAULT NULL,
  `result`      JSON         DEFAULT NULL,
  `scanned_by`  INT          DEFAULT NULL,
  `scanned_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `scan_date`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`scanned_by`) REFERENCES `accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 12. RESPONSE / SOAR
-- ============================================================
CREATE TABLE IF NOT EXISTS `response_config` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `trigger`     VARCHAR(100) DEFAULT NULL,
  `action`      TEXT,
  `is_active`   TINYINT(1)   DEFAULT 1,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `response_actions` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `config_id`   INT          DEFAULT NULL,
  `incident_id` INT          DEFAULT NULL,
  `action`      TEXT,
  `result`      TEXT,
  `executed_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`config_id`)   REFERENCES `response_config`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 13. CONFIG BACKUPS
-- ============================================================
CREATE TABLE IF NOT EXISTS `config_backups` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `device_id`   INT          DEFAULT NULL,
  `filename`    VARCHAR(255) DEFAULT NULL,
  `filepath`    VARCHAR(500) DEFAULT NULL,
  `size`        INT          DEFAULT NULL,
  `uploaded_by` INT          DEFAULT NULL,
  `uploaded_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`device_id`)   REFERENCES `devices`(`id`)  ON DELETE SET NULL,
  FOREIGN KEY (`uploaded_by`) REFERENCES `accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `analysis_history` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `type`        VARCHAR(50)  DEFAULT NULL,
  `target`      VARCHAR(255) DEFAULT NULL,
  `result`      JSON         DEFAULT NULL,
  `user_id`     INT          DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 14. API CONFIG
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_config` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `service`    VARCHAR(100) NOT NULL UNIQUE,
  `api_key`    VARCHAR(500) DEFAULT NULL,
  `endpoint`   VARCHAR(500) DEFAULT NULL,
  `is_active`  TINYINT(1)   DEFAULT 1,
  `updated_at` DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `api_config` (`service`, `is_active`) VALUES
('virustotal', 0),
('abuseipdb',  0),
('shodan',     0);

-- ============================================================
-- 15. USEFUL VIEWS
-- ============================================================
CREATE OR REPLACE VIEW `v_active_alerts_summary` AS
SELECT
  i.id, i.title, i.severity, i.status,
  i.created_at, a.name AS assigned_to_name
FROM incidents i
LEFT JOIN accounts a ON i.assigned_to = a.id
WHERE i.status IN ('open','in_progress');

CREATE OR REPLACE VIEW `v_recent_security_events` AS
SELECT
  se.id, se.source_ip, se.severity, se.message, se.received_at,
  ss.appliance_type
FROM syslog_entries se
LEFT JOIN syslog_sources ss ON se.source_id = ss.id
WHERE se.severity <= 4
ORDER BY se.received_at DESC;

CREATE OR REPLACE VIEW `v_ddos` AS
SELECT source_ip, COUNT(*) AS hit_count, MAX(received_at) AS last_seen
FROM syslog_entries
WHERE message LIKE '%ddos%' OR message LIKE '%flood%' OR message LIKE '%syn%'
GROUP BY source_ip
ORDER BY hit_count DESC;

CREATE OR REPLACE VIEW `v_ips` AS
SELECT source_ip, severity, message, received_at
FROM syslog_entries
WHERE message LIKE '%intrusion%' OR message LIKE '%attack%' OR message LIKE '%blocked%'
ORDER BY received_at DESC;

CREATE OR REPLACE VIEW `v_ports` AS
SELECT source_ip, message, received_at
FROM syslog_entries
WHERE message LIKE '%port scan%' OR message LIKE '%portscan%'
ORDER BY received_at DESC;

SET foreign_key_checks = 1;

-- ============================================================
-- DONE - Janus fresh schema ready
-- Tables: 35 | Views: 5 | Default admin created
-- Change admin password immediately after first login!
-- ============================================================
