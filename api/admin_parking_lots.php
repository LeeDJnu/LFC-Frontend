<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");

if (function_exists("dedupe_parking_lots")) {
    dedupe_parking_lots($pdo);
}

$stmt = $pdo->query("
    SELECT
      pl.id, pl.name, pl.address, pl.owner_id,
      u.name AS owner_name, u.email AS owner_email,
      pl.total_spaces, pl.available_spaces, pl.is_active
    FROM parking_lots pl
    INNER JOIN (
      SELECT MIN(id) AS id
      FROM parking_lots
      GROUP BY name
    ) picked ON picked.id = pl.id
    LEFT JOIN users u ON u.id = pl.owner_id
    ORDER BY pl.id ASC
");

$rows = array_map(function($row) {
    return [
        "id" => (int)$row["id"],
        "name" => $row["name"],
        "address" => $row["address"],
        "owner_id" => (int)$row["owner_id"],
        "owner_name" => $row["owner_name"],
        "owner_email" => $row["owner_email"],
        "total_spaces" => (int)$row["total_spaces"],
        "available_spaces" => (int)$row["available_spaces"],
        "is_active" => (bool)$row["is_active"],
    ];
}, $stmt->fetchAll());

json_response($rows);
?>
