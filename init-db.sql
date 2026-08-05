-- ============================================================
--  JANUS - Database Initialization Script
--  Single database: alogin
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

DROP DATABASE IF EXISTS alogin;
CREATE DATABASE alogin;
USE alogin;

-- ============================================================
-- 1. ACCOUNTS & AUTH
-- ============================================================
CREATE TABLE IF NOT EXISTS `accounts` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(50)  NOT NULL UNIQUE,
  `password`      VARCHAR(255) NOT NULL,
  `name`          VARCHAR(100) NOT NULL DEFAULT 'Administrator',
  `email`         VARCHAR(100) DEFAULT NULL,
  `role`          ENUM('admin','analyst','operator') NOT NULL DEFAULT 'admin',
  `photo`         VARCHAR(255) DEFAULT NULL,
  `last_login`    DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `is_active`     TINYINT(1)   DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default admin account (password: admin123)
INSERT IGNORE INTO `accounts` (`id`, `username`, `password`, `name`, `email`, `role`, `is_active`) VALUES 
(1, 'admin', '$2y$10$bXuUMaNORqQKdU/U7Tt9X.2kCmGmdRri.HHT8hGkZJxzco/GvDcIm', 'Administrator', 'admin@janus.local', 'admin', 1);

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
('syslog_port',  '514'),
('archive_retention_hours', '168');

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
  `is_active`        TINYINT(1)   DEFAULT 1,
  `created_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `devices` (`id`, `name`, `ip`, `type`, `model`) VALUES 
(1, 'Core Switch 01', '192.168.1.10', 'switch', 'Cisco Catalyst'),
(2, 'Edge Firewall 01', '192.168.1.1', 'firewall', 'Palo Alto Network'),
(3, 'Hypervisor 01', '192.168.1.20', 'server', 'Hypervisor'),
(4, 'Web Server VM', '192.168.1.25', 'server', 'Virtual Machine');

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
  `status`     VARCHAR(10)  NOT NULL DEFAULT 'down',
  `rtt_avg`    FLOAT        DEFAULT NULL,
  `sent`       INT          DEFAULT NULL,
  `received`   INT          DEFAULT NULL,
  `loss_pct`   FLOAT        DEFAULT NULL,
  `checked_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_device_checked` (`device_id`, `checked_at`),
  INDEX `idx_link_checked`   (`link_id`,   `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `ping_logs` (`device_id`, `status`) VALUES 
(1, 'up'), (2, 'up'), (3, 'up'), (4, 'down');

CREATE TABLE IF NOT EXISTS `ping_logs_archive` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `device_id`  INT          DEFAULT NULL,
  `link_id`    INT          DEFAULT NULL,
  `status`     VARCHAR(10)  NOT NULL DEFAULT 'down',
  `rtt_avg`    FLOAT        DEFAULT NULL,
  `sent`       INT          DEFAULT NULL,
  `received`   INT          DEFAULT NULL,
  `loss_pct`   FLOAT        DEFAULT NULL,
  `checked_at` DATETIME     DEFAULT NULL,
  INDEX `idx_arch_device` (`device_id`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `device_status_cache` (
  `device_id`          INT PRIMARY KEY,
  `status`             VARCHAR(10) NOT NULL DEFAULT 'down',
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
  `added_by`       INT          DEFAULT 1,
  `added_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `created_at`     DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `syslog_sources` (`appliance_type`, `source_ip`, `is_active`, `added_by`) VALUES
('firewall', '192.168.1.1', 1, 1),
('switch', '192.168.1.10', 1, 1);

CREATE TABLE IF NOT EXISTS `syslog_entries` (
  `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
  `source_id`      INT         DEFAULT NULL,
  `appliance_type` VARCHAR(100) DEFAULT NULL,
  `source_ip`      VARCHAR(45) DEFAULT NULL,
  `facility`       INT         DEFAULT NULL,
  `severity`       INT         DEFAULT NULL,
  `message`        TEXT,
  `raw`            TEXT,
  `src_ip`         VARCHAR(45) DEFAULT NULL,
  `dst_ip`         VARCHAR(45) DEFAULT NULL,
  `dst_port`       INT         DEFAULT NULL,
  `app`            VARCHAR(100) DEFAULT NULL,
  `service`        VARCHAR(50) DEFAULT NULL,
  `action`         VARCHAR(20) DEFAULT NULL,
  `sent_bytes`     BIGINT UNSIGNED DEFAULT 0,
  `rcvd_bytes`     BIGINT UNSIGNED DEFAULT 0,
  `duration`       INT UNSIGNED DEFAULT 0,
  `is_auth_failure` TINYINT(1) DEFAULT 0,
  `is_remote_access` TINYINT(1) DEFAULT 0,
  `is_malware`     TINYINT(1)  DEFAULT 0,
  `is_notable`     TINYINT(1)  DEFAULT 0,
  `received_at`    DATETIME    DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_syslog_source`   (`source_id`),
  INDEX `idx_syslog_received` (`received_at`),
  INDEX `idx_syslog_severity` (`severity`),
  INDEX `idx_perf_traffic`   (`received_at`, `action`),
  INDEX `idx_perf_src`       (`src_ip`, `received_at`),
  INDEX `idx_perf_dst`       (`dst_ip`, `received_at`),
  INDEX `idx_perf_app`       (`app`, `received_at`),
  INDEX `idx_syslog_perf_flags` (`is_auth_failure`, `is_remote_access`, `is_malware`, `is_notable`, `received_at`),
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
  `src_ip`         VARCHAR(45) DEFAULT NULL,
  `dst_ip`         VARCHAR(45) DEFAULT NULL,
  `dst_port`       INT         DEFAULT NULL,
  `app`            VARCHAR(100) DEFAULT NULL,
  `service`        VARCHAR(50) DEFAULT NULL,
  `action`         VARCHAR(20) DEFAULT NULL,
  `sent_bytes`     BIGINT UNSIGNED DEFAULT 0,
  `rcvd_bytes`     BIGINT UNSIGNED DEFAULT 0,
  `duration`       INT UNSIGNED DEFAULT 0,
  `is_auth_failure` TINYINT(1) DEFAULT 0,
  `is_remote_access` TINYINT(1) DEFAULT 0,
  `is_malware`     TINYINT(1)  DEFAULT 0,
  `is_notable`     TINYINT(1)  DEFAULT 0,
  `received_at`    DATETIME    DEFAULT NULL,
  INDEX `idx_arch_syslog_received` (`received_at`),
  INDEX `idx_arch_perf_traffic`   (`received_at`, `action`),
  INDEX `idx_arch_perf_src`       (`src_ip`, `received_at`),
  INDEX `idx_arch_perf_dst`       (`dst_ip`, `received_at`),
  INDEX `idx_arch_perf_app`       (`app`, `received_at`),
  INDEX `idx_archive_perf_flags`  (`is_auth_failure`, `is_remote_access`, `is_malware`, `is_notable`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `syslog_traffic_daily` (
  `log_date` DATE NOT NULL,
  `source_ip` VARCHAR(45) NOT NULL,
  `destination_ip` VARCHAR(45) NOT NULL,
  `app` VARCHAR(100) NOT NULL,
  `service` VARCHAR(50) NOT NULL,
  `action` VARCHAR(20) NOT NULL,
  `flow_count` INT UNSIGNED DEFAULT 0,
  `total_sent` BIGINT UNSIGNED DEFAULT 0,
  `total_rcvd` BIGINT UNSIGNED DEFAULT 0,
  PRIMARY KEY (`log_date`, `source_ip`, `destination_ip`, `app`, `service`, `action`),
  INDEX `idx_date` (`log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `syslog_traffic_hourly` (
  `log_hour` DATETIME NOT NULL,
  `source_ip` VARCHAR(45) NOT NULL,
  `flow_count` INT UNSIGNED DEFAULT 0,
  `total_sent` BIGINT UNSIGNED DEFAULT 0,
  `total_rcvd` BIGINT UNSIGNED DEFAULT 0,
  PRIMARY KEY (`log_hour`, `source_ip`),
  INDEX `idx_hour` (`log_hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. SOC - INCIDENTS & TICKETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `incidents` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `title`               VARCHAR(255) NOT NULL,
  `description`         TEXT,
  `severity`            ENUM('low','medium','high','critical') DEFAULT 'medium',
  `status`              VARCHAR(20) DEFAULT 'open',
  `type`                VARCHAR(100) DEFAULT NULL,
  `subcategory`         VARCHAR(100) DEFAULT NULL,
  `assigned_to`         INT          DEFAULT NULL,
  `reported_by`         INT          NOT NULL DEFAULT 1,
  `device_id`           INT          DEFAULT NULL,
  `due_date`            DATETIME     DEFAULT NULL,
  `resolved_at`         DATETIME     DEFAULT NULL,
  `unread_by_assignee`  TINYINT(1)   DEFAULT 1,
  `attachment`          VARCHAR(255) DEFAULT NULL,
  `created_at`          DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_incident_status`   (`status`),
  INDEX `idx_incident_assigned` (`assigned_to`),
  FOREIGN KEY (`assigned_to`) REFERENCES `accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reported_by`) REFERENCES `accounts`(`id`) ON DELETE CASCADE
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
  `changed_by`  INT          NOT NULL DEFAULT 1,
  `field`       VARCHAR(100) NOT NULL,
  `old_value`   TEXT,
  `new_value`   TEXT,
  `changed_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`)  REFERENCES `accounts`(`id`)  ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS `janus_ipam` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `ip`          VARCHAR(45)  NOT NULL UNIQUE,
  `mac`         VARCHAR(50)  DEFAULT NULL,
  `assigned_to` VARCHAR(255) DEFAULT NULL,
  `status`      ENUM('active','inactive','reserved') NOT NULL DEFAULT 'inactive',
  `vlan`        VARCHAR(50)  DEFAULT NULL,
  `notes`       TEXT         DEFAULT NULL,
  `first_seen`  DATETIME     DEFAULT NULL,
  `last_seen`   DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `janus_ipam` (`ip`, `status`) VALUES 
('192.168.1.1', 'active'),
('192.168.1.10', 'active'),
('192.168.1.20', 'active'),
('192.168.1.25', 'inactive'),
('192.168.1.100', 'reserved');

CREATE TABLE IF NOT EXISTS `janus_ipam_log` (
  `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ip`              VARCHAR(45) NOT NULL,
  `old_mac`         VARCHAR(50) DEFAULT NULL,
  `new_mac`         VARCHAR(50) DEFAULT NULL,
  `note`            TEXT        DEFAULT NULL,
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
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `hostname`        VARCHAR(255) NOT NULL DEFAULT '',
  `name`            VARCHAR(100) DEFAULT NULL,
  `ip`              VARCHAR(45)  NOT NULL UNIQUE,
  `model`           VARCHAR(100) DEFAULT NULL,
  `vendor`          VARCHAR(50)  DEFAULT NULL,
  `platform_key`    VARCHAR(50)  DEFAULT 'ios',
  `ssh_user`        VARCHAR(100) DEFAULT NULL,
  `ssh_port`        INT          NOT NULL DEFAULT 22,
  `connection_type` VARCHAR(10)  NOT NULL DEFAULT 'ssh',
  `snmp_community`  VARCHAR(128) DEFAULT NULL,
  `snmp_version`    INT          NOT NULL DEFAULT 2,
  `snmp_port`       INT          NOT NULL DEFAULT 161,
  `location`        VARCHAR(255) DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `enabled`         TINYINT(1)   NOT NULL DEFAULT 1,
  `poll_status`     VARCHAR(20)  DEFAULT 'pending',
  `poll_error`      TEXT         DEFAULT NULL,
  `last_polled`     DATETIME     DEFAULT NULL,
  `poll_duration`   DOUBLE       DEFAULT 0,
  `mac_count`       INT          DEFAULT 0,
  `port_count`      INT          DEFAULT 0,
  `group_id`        INT          DEFAULT NULL,
  `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_ports` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `switch_id`    INT          NOT NULL,
  `port_index`   INT          DEFAULT NULL,
  `port_name`    VARCHAR(100) DEFAULT NULL,
  `port_type`    VARCHAR(50)  DEFAULT 'access',
  `admin_status` VARCHAR(20)  DEFAULT 'up',
  `link_status`  VARCHAR(20)  DEFAULT 'down',
  `vlan_names`   VARCHAR(255) DEFAULT NULL,
  `is_trunk`     TINYINT(1)   DEFAULT 0,
  `mac_count`    INT          DEFAULT 0,
  `description`  VARCHAR(255) DEFAULT NULL,
  `oper_status`  VARCHAR(20)  DEFAULT NULL,
  `speed`        BIGINT       DEFAULT NULL,
  `last_updated` DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_switch_port` (`switch_id`, `port_name`),
  FOREIGN KEY (`switch_id`) REFERENCES `nac_switches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_port_macs` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `switch_id`  INT          NOT NULL,
  `port_id`    INT          DEFAULT NULL,
  `port_name`  VARCHAR(100) DEFAULT NULL,
  `mac`        VARCHAR(20)  NOT NULL,
  `vlan`       INT          DEFAULT NULL,
  `vlan_name`  VARCHAR(100) DEFAULT NULL,
  `mac_type`   VARCHAR(20)  DEFAULT 'D',
  `first_seen` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `last_seen`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `seen_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_nac_mac` (`mac`),
  UNIQUE KEY `uq_switch_port_mac` (`switch_id`, `port_name`, `mac`),
  FOREIGN KEY (`switch_id`) REFERENCES `nac_switches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nac_mac_ip_map` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `mac`             VARCHAR(20)  NOT NULL,
  `ip`              VARCHAR(45)  DEFAULT NULL,
  `hostname`        VARCHAR(255) DEFAULT NULL,
  `subnet`          VARCHAR(43)  DEFAULT NULL,
  `ipam_status`     VARCHAR(50)  DEFAULT NULL,
  `ipam_last_seen`  DATETIME     DEFAULT NULL,
  `nac_switch_id`   INT          DEFAULT NULL,
  `nac_switch_name` VARCHAR(255) DEFAULT NULL,
  `nac_port`        VARCHAR(100) DEFAULT NULL,
  `nac_vlan`        VARCHAR(100) DEFAULT NULL,
  `oui_vendor`      VARCHAR(100) DEFAULT NULL,
  `last_seen`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_mac` (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `janus_printers` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(100) NOT NULL,
  `ip`              VARCHAR(45)  NOT NULL UNIQUE,
  `vendor`          VARCHAR(50)  DEFAULT 'HP',
  `model`           VARCHAR(100) DEFAULT NULL,
  `location`        VARCHAR(255) DEFAULT NULL,
  `snmp_community`  VARCHAR(100) DEFAULT 'public',
  `snmp_version`    VARCHAR(5)   DEFAULT '2c',
  `snmp_port`       INT          DEFAULT 161,
  `status`          VARCHAR(20)  DEFAULT 'online',
  `last_status_msg` TEXT         DEFAULT NULL,
  `toner_level`     INT          DEFAULT 100,
  `paper_status`    VARCHAR(50)  DEFAULT 'OK',
  `last_polled`     DATETIME     DEFAULT NULL,
  `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
  `incident_id` INT          NOT NULL,
  `type`        VARCHAR(100) NOT NULL,
  `value`       VARCHAR(255) NOT NULL,
  `notes`       TEXT         DEFAULT NULL,
  FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 12. AGENT & ENDPOINT TABLES
-- ============================================================
CREATE TABLE IF NOT EXISTS `agent_tokens` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `token`      VARCHAR(255) NOT NULL UNIQUE,
    `active`     TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `agent_tokens` (`token`, `active`) VALUES ('janus_telemetry_secure_token_2026', 1);

CREATE TABLE IF NOT EXISTS `endpoints` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `hostname`      VARCHAR(255) NOT NULL UNIQUE,
    `ip_address`    VARCHAR(45) DEFAULT NULL,
    `os_version`    VARCHAR(100) DEFAULT NULL,
    `agent_version` VARCHAR(50) DEFAULT NULL,
    `first_seen`    DATETIME DEFAULT NULL,
    `last_seen`     DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `endpoint_logons` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `endpoint_id`  INT NOT NULL,
    `username`     VARCHAR(100) DEFAULT NULL,
    `domain_name`  VARCHAR(100) DEFAULT NULL,
    `logon_type`    VARCHAR(50) DEFAULT NULL,
    `logon_time`    DATETIME DEFAULT NULL,
    `event_id`      INT DEFAULT 0,
    `source_ip`    VARCHAR(45) DEFAULT NULL,
    `collected_at` DATETIME DEFAULT NULL,
    FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `endpoint_rdp_sessions` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `endpoint_id`    INT NOT NULL,
    `username`       VARCHAR(100) DEFAULT NULL,
    `client_ip`      VARCHAR(45) DEFAULT NULL,
    `session_action` VARCHAR(50) DEFAULT NULL,
    `event_id`        INT DEFAULT 0,
    `event_time`      DATETIME DEFAULT NULL,
    `collected_at`   DATETIME DEFAULT NULL,
    FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `endpoint_apps` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `endpoint_id`  INT NOT NULL,
    `app_name`     VARCHAR(255) NOT NULL,
    `app_version`  VARCHAR(100) DEFAULT NULL,
    `publisher`    VARCHAR(255) DEFAULT NULL,
    `install_date` DATE DEFAULT NULL,
    `first_seen`   DATETIME DEFAULT NULL,
    `last_seen`    DATETIME DEFAULT NULL,
    UNIQUE KEY `uq_app` (`endpoint_id`, `app_name`),
    FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `endpoint_ports` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `endpoint_id`  INT NOT NULL,
    `local_port`   INT NOT NULL,
    `protocol`     VARCHAR(10) DEFAULT 'TCP',
    `process_name` VARCHAR(255) DEFAULT NULL,
    `process_path` VARCHAR(255) DEFAULT NULL,
    `pid`          INT DEFAULT NULL,
    `collected_at` DATETIME DEFAULT NULL,
    FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 13. API CONFIG
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
-- 14. USEFUL VIEWS
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
