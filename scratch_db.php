<?php
require_once __DIR__ . '/../db_connect.php';

$res = $mysqli->query("DESCRIBE incidents");
$cols = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cols[] = $row;
    }
} else {
    echo $mysqli->error;
}
echo json_encode($cols);
?>
