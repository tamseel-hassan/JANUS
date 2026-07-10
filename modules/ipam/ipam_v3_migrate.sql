-- ════════════════════════════════════════════════════════════════
--  JanusIPAM v3 – Migration
--  Run: mysql -u root -p alogin < ipam_v3_migrate.sql
-- ════════════════════════════════════════════════════════════════

-- ── Subnet registry ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ipam_subnets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cidr        VARCHAR(43)  NOT NULL,
    label       VARCHAR(128) DEFAULT NULL,
    vlan_id     VARCHAR(32)  DEFAULT NULL,
    description TEXT         DEFAULT NULL,
    interface   VARCHAR(32)  DEFAULT NULL,   -- auto-detected if NULL
    scan_enabled TINYINT(1)  NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cidr (cidr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seed subnets from existing IP data ───────────────────────────
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

-- ── Ensure janus_ipam has all columns ────────────────────────────
ALTER TABLE janus_ipam
    MODIFY COLUMN status ENUM('active','inactive','reserved') NOT NULL DEFAULT 'inactive';

-- ── Ensure janus_ipam_log has all columns ────────────────────────
-- (add individually to avoid IF NOT EXISTS multi-column issue on older builds)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='alogin' AND TABLE_NAME='janus_ipam_log' AND COLUMN_NAME='acknowledged');
SET @sql = IF(@col=0,
    'ALTER TABLE janus_ipam_log ADD COLUMN acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER changed_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='alogin' AND TABLE_NAME='janus_ipam_log' AND COLUMN_NAME='acknowledged_by');
SET @sql = IF(@col=0,
    'ALTER TABLE janus_ipam_log ADD COLUMN acknowledged_by VARCHAR(128) DEFAULT NULL AFTER acknowledged',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='alogin' AND TABLE_NAME='janus_ipam_log' AND COLUMN_NAME='ack_note');
SET @sql = IF(@col=0,
    'ALTER TABLE janus_ipam_log ADD COLUMN ack_note TEXT DEFAULT NULL AFTER acknowledged_by',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='alogin' AND TABLE_NAME='janus_ipam_log' AND COLUMN_NAME='acknowledged_at');
SET @sql = IF(@col=0,
    'ALTER TABLE janus_ipam_log ADD COLUMN acknowledged_at DATETIME DEFAULT NULL AFTER ack_note',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Indexes ───────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_log_ip     ON janus_ipam_log (ip);
CREATE INDEX IF NOT EXISTS idx_log_acked  ON janus_ipam_log (acknowledged);
CREATE INDEX IF NOT EXISTS idx_log_ts     ON janus_ipam_log (changed_at);
