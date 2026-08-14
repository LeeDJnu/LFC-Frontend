<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");

if (function_exists("dedupe_parking_lots")) {
    dedupe_parking_lots($pdo);
}

$stmt = $pdo->query("
    SELECT name, COUNT(*) AS cnt
    FROM parking_lots
    GROUP BY name
    ORDER BY name ASC
");

$rows = array();
foreach ($stmt->fetchAll() as $row) {
    $rows[] = array(
        "name" => $row["name"],
        "count" => (int)$row["cnt"]
    );
}

json_response(array(
    "ok" => true,
    "message" => "주차장 중복 정리 완료",
    "parking_lot_name_counts" => $rows
));
?>
