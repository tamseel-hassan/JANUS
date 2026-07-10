<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Check if user is logged in (reuse your existing authentication)
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// MySQL connection
$mysql_host = 'localhost';
$mysql_user = 'root';
$mysql_pass = '';
$mysql_db = 'mrp';

// Get date from query string or use today
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

// Connect to MySQL
$conn = new mysqli($mysql_host, $mysql_user, $mysql_pass, $mysql_db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Helper function to get data
function getData($conn, $sql) {
    $result = $conn->query($sql);
    $data = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
}

$date_escaped = $conn->real_escape_string($date);

// Get all the data - these are the SAME queries as your mrpg.php
$response = [
    'date' => $date,
    'document_types' => getData($conn, 
        "SELECT pp_category as category, count as count 
         FROM document_type_stats 
         WHERE record_date = '$date_escaped'"),
    
    'application_types' => getData($conn,
        "SELECT app_type as type, count as count 
         FROM application_type_stats 
         WHERE record_date = '$date_escaped'"),
    
    'passport_types' => getData($conn,
        "SELECT pp_type as type, count as count 
         FROM passport_type_stats 
         WHERE record_date = '$date_escaped'"),
    
    'office_summary' => getData($conn,
        "SELECT category, load_count as load, amount 
         FROM office_category_stats 
         WHERE record_date = '$date_escaped'"),
    
    'hourly_data' => getData($conn,
        "SELECT hour, count 
         FROM hourly_timeline_stats 
         WHERE record_date = '$date_escaped' 
         ORDER BY CAST(hour AS UNSIGNED)")
];

$conn->close();

// Tell browser we're sending JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow React to access this API
echo json_encode($response);
?>
