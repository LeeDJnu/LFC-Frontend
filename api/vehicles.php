<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $stmt = $pdo->prepare("
        SELECT id, plate_number, created_at
        FROM vehicles
        WHERE user_id = ?
        ORDER BY id DESC
    ");
    $stmt->execute([$user["id"]]);
    $rows = array_map(function($row) {
        return [
            "id" => (int)$row["id"],
            "plate_number" => $row["plate_number"],
            "created_at" => $row["created_at"],
        ];
    }, $stmt->fetchAll());
    json_response($rows);
}

if ($method === "POST") {
    if ($user["role"] !== "user") error_response("사용자 계정만 차량을 등록할 수 있습니다.", 403);

    $body = json_body();
    $plate = trim($body["plate_number"] ?? "");

    if ($plate === "") error_response("차량번호를 입력하세요.", 400);

    $stmt = $pdo->prepare("
        INSERT INTO vehicles (user_id, plate_number, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$user["id"], $plate]);

    $stmt = $pdo->prepare("SELECT id, plate_number, created_at FROM vehicles WHERE id = ?");
    $stmt->execute([$pdo->lastInsertId()]);
    $row = $stmt->fetch();

    json_response([
        "id" => (int)$row["id"],
        "plate_number" => $row["plate_number"],
        "created_at" => $row["created_at"],
    ]);
}

error_response("지원하지 않는 요청입니다.", 405);
?>
