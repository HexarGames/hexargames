<?php
$host = "localhost";
$user = "hexargam_faceboo";        // your cPanel DB username
$pass = "hexar@games"; // your DB password
$db   = "hexargam_facebook";         // your DB name

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
  die("Database connection failed: " . $conn->connect_error);
}
?>
