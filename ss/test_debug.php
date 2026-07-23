<?php
require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die("Connection failed: " . mysqli_connect_error() . "\n");
}

$start = date('Y-m-d H:i:s', strtotime('-24 hours'));
$end = date('Y-m-d H:i:s');

echo "Debug dates: Start = $start, End = $end\n";

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;
echo "Has archive table: " . ($has_archive ? 'Yes' : 'No') . "\n";

$archive_query = $has_archive ? "
    UNION ALL
    SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at
    FROM syslog_entries_archive
    WHERE received_at BETWEEN '$start' AND '$end'
      AND src_ip IS NOT NULL
" : "";

$query = "
    SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at
    FROM (
        SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at
        FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end'
          AND src_ip IS NOT NULL
        $archive_query
    ) AS combined
    ORDER BY received_at DESC
";

$res = mysqli_query($con, $query);
if (!$res) {
    echo "SQL Query failed: " . mysqli_error($con) . "\n";
    exit;
}

$num_rows = mysqli_num_rows($res);
echo "SQL Query returned: $num_rows rows\n";

$count_valid = 0;
$i = 0;
while ($row = mysqli_fetch_assoc($res)) {
    if ($i < 5) {
        echo "Row $i: src_ip={$row['src_ip']}, dst_ip={$row['dst_ip']}, sent_bytes={$row['sent_bytes']}, rcvd_bytes={$row['rcvd_bytes']}\n";
    }
    if (isset($row['src_ip'], $row['dst_ip'])) {
        $count_valid++;
    }
    $i++;
}
echo "Total parsed valid rows inside loop: $count_valid\n";
mysqli_close($con);
