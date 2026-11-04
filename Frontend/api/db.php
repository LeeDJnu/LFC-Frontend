<?php

$DB_HOST = "localhost";
$DB_NAME = "lfc";
$DB_USER = "lfc";
$DB_PASS = "flqjvnf1!";

function db_json_error($message, $status = 500) {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(array("detail" => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

if (strpos($DB_NAME, "ENTER_") !== false || strpos($DB_USER, "ENTER_") !== false || strpos($DB_PASS, "ENTER_") !== false) {
    db_json_error("Enter the Dothome MySQL DB information in api/db.php first. The DB name, DB user, and DB password are still default values.", 500);
}

try {
    $pdo = new PDO(
        "mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (PDOException $e) {
    db_json_error("DB connection failed. Check the DB name, user, password, and host in api/db.php. Cause: " . $e->getMessage(), 500);
}
?>
