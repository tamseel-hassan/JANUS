-- Database Initialization Script for JANUS

CREATE DATABASE IF NOT EXISTS alogin;
USE alogin;

-- 1. Accounts Table
CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'analyst',
    email VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default admin account (password is 'admin123')
INSERT IGNORE INTO accounts (username, password, role, is_active) VALUES 
('admin', '$2y$10$bXuUMaNORqQKdU/U7Tt9X.2kCmGmdRri.HHT8hGkZJxzco/GvDcIm', 'admin', 1);

-- 2. Devices Table
CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL, -- 'server', 'switch', 'firewall'
    model VARCHAR(100) DEFAULT 'Unknown',
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed some sample devices
INSERT IGNORE INTO devices (name, ip, type, model) VALUES 
('Core Switch 01', '192.168.1.10', 'switch', 'Cisco Catalyst'),
('Edge Firewall 01', '192.168.1.1', 'firewall', 'Palo Alto Network'),
('Hypervisor 01', '192.168.1.20', 'server', 'Hypervisor'),
('Web Server VM', '192.168.1.25', 'server', 'Virtual Machine');

-- 3. Ping Logs Table
CREATE TABLE IF NOT EXISTS ping_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    link_id INT DEFAULT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'down',
    checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed initial ping logs
INSERT IGNORE INTO ping_logs (device_id, status) VALUES 
(1, 'up'), (2, 'up'), (3, 'up'), (4, 'down');

-- 4. Incidents Table
CREATE TABLE IF NOT EXISTS incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    type VARCHAR(100) DEFAULT NULL,
    subcategory VARCHAR(100) DEFAULT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    device_id INT DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    reported_by INT NOT NULL,
    status VARCHAR(20) DEFAULT 'open',
    due_date DATETIME DEFAULT NULL,
    unread_by_assignee TINYINT(1) DEFAULT 1,
    attachment VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reported_by) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Incident History Table
CREATE TABLE IF NOT EXISTS incident_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    changed_by INT NOT NULL,
    field VARCHAR(100) NOT NULL,
    old_value VARCHAR(255) DEFAULT NULL,
    new_value VARCHAR(255) DEFAULT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Observables Table
CREATE TABLE IF NOT EXISTS observables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    type VARCHAR(100) NOT NULL,
    value VARCHAR(255) NOT NULL,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Agent Tokens Table
CREATE TABLE IF NOT EXISTS agent_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(255) NOT NULL UNIQUE,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO agent_tokens (token, active) VALUES ('janus_telemetry_secure_token_2026', 1);

-- 8. Endpoints Table
CREATE TABLE IF NOT EXISTS endpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostname VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) DEFAULT NULL,
    os_version VARCHAR(100) DEFAULT NULL,
    agent_version VARCHAR(50) DEFAULT NULL,
    first_seen DATETIME DEFAULT NULL,
    last_seen DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Endpoint Logons Table
CREATE TABLE IF NOT EXISTS endpoint_logons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_id INT NOT NULL,
    username VARCHAR(100) DEFAULT NULL,
    domain_name VARCHAR(100) DEFAULT NULL,
    logon_type VARCHAR(50) DEFAULT NULL,
    logon_time DATETIME DEFAULT NULL,
    event_id INT DEFAULT 0,
    source_ip VARCHAR(45) DEFAULT NULL,
    collected_at DATETIME DEFAULT NULL,
    FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Endpoint RDP Sessions Table
CREATE TABLE IF NOT EXISTS endpoint_rdp_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_id INT NOT NULL,
    username VARCHAR(100) DEFAULT NULL,
    client_ip VARCHAR(45) DEFAULT NULL,
    session_action VARCHAR(50) DEFAULT NULL,
    event_id INT DEFAULT 0,
    event_time DATETIME DEFAULT NULL,
    collected_at DATETIME DEFAULT NULL,
    FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Endpoint Apps Table
CREATE TABLE IF NOT EXISTS endpoint_apps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_id INT NOT NULL,
    app_name VARCHAR(255) NOT NULL,
    app_version VARCHAR(100) DEFAULT NULL,
    publisher VARCHAR(255) DEFAULT NULL,
    install_date DATE DEFAULT NULL,
    first_seen DATETIME DEFAULT NULL,
    last_seen DATETIME DEFAULT NULL,
    UNIQUE KEY uq_app (endpoint_id, app_name),
    FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Endpoint Ports Table
CREATE TABLE IF NOT EXISTS endpoint_ports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_id INT NOT NULL,
    local_port INT NOT NULL,
    protocol VARCHAR(10) DEFAULT 'TCP',
    process_name VARCHAR(255) DEFAULT NULL,
    process_path VARCHAR(255) DEFAULT NULL,
    pid INT DEFAULT NULL,
    collected_at DATETIME DEFAULT NULL,
    FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Janus IPAM Table
CREATE TABLE IF NOT EXISTS janus_ipam (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    mac VARCHAR(50) DEFAULT NULL,
    status ENUM('active','inactive','reserved') NOT NULL DEFAULT 'inactive',
    first_seen DATETIME DEFAULT NULL,
    last_seen DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed some IPAM entries
INSERT IGNORE INTO janus_ipam (ip, status) VALUES 
('192.168.1.1', 'active'),
('192.168.1.10', 'active'),
('192.168.1.20', 'active'),
('192.168.1.25', 'inactive'),
('192.168.1.100', 'reserved');

-- 14. Janus IPAM Log Table
CREATE TABLE IF NOT EXISTS janus_ipam_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    old_mac VARCHAR(50) DEFAULT NULL,
    new_mac VARCHAR(50) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    acknowledged_by VARCHAR(128) DEFAULT NULL,
    ack_note TEXT DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. Config and Syslog Tables
CREATE TABLE IF NOT EXISTS system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) UNIQUE NOT NULL,
    `value` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO system_config (`key`, `value`) VALUES ('archive_retention_hours', '168');

CREATE TABLE IF NOT EXISTS syslog_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appliance_type VARCHAR(100) NOT NULL,
    source_ip VARCHAR(45) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    added_by INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO syslog_sources (appliance_type, source_ip, is_active, added_by) VALUES
('firewall', '192.168.1.1', 1, 1),
('switch', '192.168.1.10', 1, 1);

CREATE TABLE IF NOT EXISTS syslog_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NULL,
    appliance_type VARCHAR(100) NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    message TEXT NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (source_ip),
    INDEX (received_at),
    FOREIGN KEY (source_id) REFERENCES syslog_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. IPAM Migration Script (ipam_v3_migrate.sql)
CREATE TABLE IF NOT EXISTS ipam_subnets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cidr        VARCHAR(43)  NOT NULL,
    label       VARCHAR(128) DEFAULT NULL,
    vlan_id     VARCHAR(32)  DEFAULT NULL,
    description TEXT         DEFAULT NULL,
    interface   VARCHAR(32)  DEFAULT NULL,
    scan_enabled TINYINT(1)  NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cidr (cidr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ipam_subnets (cidr, label, scan_enabled)
SELECT DISTINCT
    CONCAT(
        SUBSTRING_INDEX(ip,'.',1),'.',
        SUBSTRING_INDEX(SUBSTRING_INDEX(ip,'.',2),'.',-1),'.',
        SUBSTRING_INDEX(SUBSTRING_INDEX(ip,'.',3),'.',-1),
        '.0/24'
    ) AS cidr,
    CONCAT(
        SUBSTRING_INDEX(ip,'.',1),'.',
        SUBSTRING_INDEX(SUBSTRING_INDEX(ip,'.',2),'.',-1),'.',
        SUBSTRING_INDEX(SUBSTRING_INDEX(ip,'.',3),'.',-1),
        '.x/24'
    ) AS label,
    1
FROM janus_ipam;

CREATE INDEX idx_log_ip     ON janus_ipam_log (ip);
CREATE INDEX idx_log_acked  ON janus_ipam_log (acknowledged);
CREATE INDEX idx_log_ts     ON janus_ipam_log (changed_at);
CREATE TABLE IF NOT EXISTS syslog_entries_archive LIKE syslog_entries;
