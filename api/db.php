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

if (strpos($DB_NAME, "여기에_") !== false || strpos($DB_USER, "여기에_") !== false || strpos($DB_PASS, "여기에_") !== false) {
    db_json_error("api/db.php에 닷홈 MySQL DB 정보를 먼저 입력하세요. DB명, DB아이디, DB비밀번호가 아직 기본값입니다.", 500);
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
    db_json_error("DB 연결 실패: api/db.php의 DB명/아이디/비밀번호/호스트를 확인하세요. 원인: " . $e->getMessage(), 500);
}
?>
