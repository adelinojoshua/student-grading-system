
<?php
// Student Grading System
// Example Database Configuration
// Copy this file as db.php and configure your database.

// Enable MySQL error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Local XAMPP database settings (example only)
$db_host = '127.0.0.1';
$db_user = 'root';
$db_password = '';
$db_name = 'student_grading_system';
$db_port = 3307;

// Connect to database
try {
    $conn = new mysqli(
        $db_host,
        $db_user,
        $db_password,
        $db_name,
        $db_port
    );

    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {
    http_response_code(503);
    exit("Database connection failed. Check db.php.");
}
