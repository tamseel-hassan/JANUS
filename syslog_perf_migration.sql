-- Janus Syslog High Performance Schema Migration
USE alogin;

-- 1. Add structured columns to raw syslog tables (Standard SQL)
ALTER TABLE syslog_entries
    ADD COLUMN src_ip VARCHAR(45) DEFAULT NULL,
    ADD COLUMN dst_ip VARCHAR(45) DEFAULT NULL,
    ADD COLUMN dst_port INT DEFAULT NULL,
    ADD COLUMN app VARCHAR(100) DEFAULT NULL,
    ADD COLUMN service VARCHAR(50) DEFAULT NULL,
    ADD COLUMN action VARCHAR(20) DEFAULT NULL,
    ADD COLUMN sent_bytes BIGINT UNSIGNED DEFAULT 0,
    ADD COLUMN rcvd_bytes BIGINT UNSIGNED DEFAULT 0,
    ADD COLUMN duration INT UNSIGNED DEFAULT 0;

ALTER TABLE syslog_entries_archive
    ADD COLUMN src_ip VARCHAR(45) DEFAULT NULL,
    ADD COLUMN dst_ip VARCHAR(45) DEFAULT NULL,
    ADD COLUMN dst_port INT DEFAULT NULL,
    ADD COLUMN app VARCHAR(100) DEFAULT NULL,
    ADD COLUMN service VARCHAR(50) DEFAULT NULL,
    ADD COLUMN action VARCHAR(20) DEFAULT NULL,
    ADD COLUMN sent_bytes BIGINT UNSIGNED DEFAULT 0,
    ADD COLUMN rcvd_bytes BIGINT UNSIGNED DEFAULT 0,
    ADD COLUMN duration INT UNSIGNED DEFAULT 0;

-- 2. Add high-performance B-Tree indexes for structured filtering
ALTER TABLE syslog_entries
    ADD INDEX idx_perf_traffic (received_at, action),
    ADD INDEX idx_perf_src (src_ip, received_at),
    ADD INDEX idx_perf_dst (dst_ip, received_at),
    ADD INDEX idx_perf_app (app, received_at);

ALTER TABLE syslog_entries_archive
    ADD INDEX idx_perf_traffic (received_at, action),
    ADD INDEX idx_perf_src (src_ip, received_at),
    ADD INDEX idx_perf_dst (dst_ip, received_at),
    ADD INDEX idx_perf_app (app, received_at);

-- 3. Create Daily Rollup/Aggregate Table for Traffic
CREATE TABLE IF NOT EXISTS syslog_traffic_daily (
    log_date DATE NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    destination_ip VARCHAR(45) NOT NULL,
    app VARCHAR(100) NOT NULL,
    service VARCHAR(50) NOT NULL,
    action VARCHAR(20) NOT NULL,
    flow_count INT UNSIGNED DEFAULT 0,
    total_sent BIGINT UNSIGNED DEFAULT 0,
    total_rcvd BIGINT UNSIGNED DEFAULT 0,
    PRIMARY KEY (log_date, source_ip, destination_ip, app, service, action),
    INDEX idx_date (log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create Hourly Rollup/Aggregate Table for Charts
CREATE TABLE IF NOT EXISTS syslog_traffic_hourly (
    log_hour DATETIME NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    flow_count INT UNSIGNED DEFAULT 0,
    total_sent BIGINT UNSIGNED DEFAULT 0,
    total_rcvd BIGINT UNSIGNED DEFAULT 0,
    PRIMARY KEY (log_hour, source_ip),
    INDEX idx_hour (log_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Add pre-classification flag columns and indexes for high-speed dashboards
ALTER TABLE syslog_entries
    ADD COLUMN is_auth_failure TINYINT(1) DEFAULT 0,
    ADD COLUMN is_remote_access TINYINT(1) DEFAULT 0,
    ADD COLUMN is_malware TINYINT(1) DEFAULT 0,
    ADD COLUMN is_notable TINYINT(1) DEFAULT 0;

ALTER TABLE syslog_entries_archive
    ADD COLUMN is_auth_failure TINYINT(1) DEFAULT 0,
    ADD COLUMN is_remote_access TINYINT(1) DEFAULT 0,
    ADD COLUMN is_malware TINYINT(1) DEFAULT 0,
    ADD COLUMN is_notable TINYINT(1) DEFAULT 0;

ALTER TABLE syslog_entries
    ADD INDEX idx_perf_auth_fail (is_auth_failure, received_at),
    ADD INDEX idx_perf_remote (is_remote_access, received_at),
    ADD INDEX idx_perf_malware (is_malware, received_at),
    ADD INDEX idx_perf_notable (is_notable, received_at);

ALTER TABLE syslog_entries_archive
    ADD INDEX idx_perf_auth_fail (is_auth_failure, received_at),
    ADD INDEX idx_perf_remote (is_remote_access, received_at),
    ADD INDEX idx_perf_malware (is_malware, received_at),
    ADD INDEX idx_perf_notable (is_notable, received_at);
