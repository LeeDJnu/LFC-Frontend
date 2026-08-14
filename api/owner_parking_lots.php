<?php
require_once __DIR__ . "/common.php";
$user = require_role($pdo, "owner");

if (function_exists("dedupe_parking_lots")) {
    dedupe_parking_lots($pdo);
}

$stmt = $pdo->prepare("
    SELECT pl.*
    FROM parking_lots pl
    INNER JOIN (
      SELECT MIN(id) AS id
      FROM parking_lots
      WHERE owner_id = ?
      GROUP BY name
    ) picked ON picked.id = pl.id
    ORDER BY pl.id ASC
");
$stmt->execute([$user["id"]]);

$rows = [];
foreach ($stmt->fetchAll() as $lot) {
    $count = $pdo->prepare("
        SELECT
          SUM(CASE WHEN status IN ('pending','paid') THEN 1 ELSE 0 END) AS reserved_count,
          SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) AS parking_count,
          SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
          SUM(CASE WHEN status = 'completed' AND payment_status <> 'paid' THEN 1 ELSE 0 END) AS unpaid_count
        FROM reservations
        WHERE parking_lot_id = ?
    ");
    $count->execute([$lot["id"]]);
    $c = $count->fetch() ?: [];

    $rows[] = [
        "id" => (int)$lot["id"],
        "name" => $lot["name"],
        "address" => $lot["address"],
        "total_spaces" => (int)$lot["total_spaces"],
        "available_spaces" => (int)$lot["available_spaces"],
        "reserved_count" => (int)($c["reserved_count"] ?? 0),
        "parking_count" => (int)($c["parking_count"] ?? 0),
        "completed_count" => (int)($c["completed_count"] ?? 0),
        "unpaid_count" => (int)($c["unpaid_count"] ?? 0),
    ];
}

json_response($rows);
?>
