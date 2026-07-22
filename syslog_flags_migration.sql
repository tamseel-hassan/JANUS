USE alogin;

-- 1. Add pre-classification flag columns for high-speed dashboards safely
ALTER TABLE syslog_entries
    ADD COLUMN IF NOT EXISTS is_auth_failure TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_remote_access TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_malware TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_notable TINYINT(1) DEFAULT 0;

ALTER TABLE syslog_entries_archive
    ADD COLUMN IF NOT EXISTS is_auth_failure TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_remote_access TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_malware TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_notable TINYINT(1) DEFAULT 0;

-- 2. Add high-performance B-Tree indexes safely
ALTER TABLE syslog_entries
    ADD INDEX IF NOT EXISTS idx_perf_auth_fail (is_auth_failure, received_at),
    ADD INDEX IF NOT EXISTS idx_perf_remote (is_remote_access, received_at),
    ADD INDEX IF NOT EXISTS idx_perf_malware (is_malware, received_at),
    ADD INDEX IF NOT EXISTS idx_perf_notable (is_notable, received_at);

ALTER TABLE syslog_entries_archive
    ADD INDEX IF NOT EXISTS idx_perf_auth_fail (is_auth_failure, received_at),
    ADD INDEX IF NOT EXISTS idx_perf_remote (is_remote_access, received_at),
    ADD INDEX IF NOT EXISTS idx_perf_malware (is_malware, received_at),
    ADD INDEX IF NOT EXISTS idx_perf_notable (is_notable, received_at);
