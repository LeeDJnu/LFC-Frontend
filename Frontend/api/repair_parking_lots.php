<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");

if (function_exists("normalize_legacy_user_accounts")) {
    normalize_legacy_user_accounts($pdo);
}

if (function_exists("normalize_legacy_parking_lot_names")) {
    normalize_legacy_parking_lot_names($pdo);
}

if (function_exists("dedupe_parking_lots")) {
    dedupe_parking_lots($pdo);
}

$stmt = $pdo->query("
    SELECT
      pl.id,
      english_lot_name(pl.name) AS name,
      english_lot_address(pl.address) AS address,
      u.email AS owner_email,
      u.name AS owner_name,
      pl.total_spaces,
      pl.available_spaces
    FROM parking_lots pl
    LEFT JOIN users u ON u.id = pl.owner_id
    ORDER BY u.email ASC, pl.id ASC
");
$rows = array();
foreach ($stmt->fetchAll() as $row) {
    $rows[] = array(
        "id" => (int)$row["id"],
        "owner" => $row["owner_email"],
        "name" => $row["name"],
        "address" => $row["address"],
        "total_spaces" => (int)$row["total_spaces"],
        "available_spaces" => (int)$row["available_spaces"]
    );
}

$countStmt = $pdo->query("
    SELECT u.email AS owner_email, COUNT(pl.id) AS lot_count
    FROM users u
    LEFT JOIN parking_lots pl ON pl.owner_id = u.id
    WHERE u.email IN ('H1@example.com','H2@example.com','H3@example.com','H4@example.com','H5@example.com')
    GROUP BY u.id, u.email
    ORDER BY u.email ASC
");
$ownerCounts = array();
foreach ($countStmt->fetchAll() as $row) {
    $ownerCounts[] = array("owner" => $row["owner_email"], "lot_count" => (int)$row["lot_count"]);
}

json_response(array(
    "ok" => true,
    "message" => "Duplicate parking lot cleanup completed.",
    "owner_lot_counts" => $ownerCounts,
    "parking_lots" => $rows
));
?>
