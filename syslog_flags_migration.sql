USE alogin;

-- Add indexes (indexes already exist from perf migration, so these will be no-ops)
ALTER TABLE syslog_entries ADD INDEX idx_perf_auth_fail (is_auth_failure, received_at);
ALTER TABLE syslog_entries ADD INDEX idx_perf_remote (is_remote_access, received_at);
ALTER TABLE syslog_entries ADD INDEX idx_perf_malware (is_malware, received_at);
ALTER TABLE syslog_entries ADD INDEX idx_perf_notable (is_notable, received_at);

ALTER TABLE syslog_entries_archive ADD INDEX idx_perf_auth_fail (is_auth_failure, received_at);
ALTER TABLE syslog_entries_archive ADD INDEX idx_perf_remote (is_remote_access, received_at);
ALTER TABLE syslog_entries_archive ADD INDEX idx_perf_malware (is_malware, received_at);
ALTER TABLE syslog_entries_archive ADD INDEX idx_perf_notable (is_notable, received_at);
