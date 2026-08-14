<?php
require_once __DIR__ . "/common.php";
$user = require_role($pdo, "owner");
$lotId = (int)(isset($_GET["parking_lot_id"])?$_GET["parking_lot_id"]:0);
$sql = "SELECT r.* FROM reservations r JOIN parking_lots pl ON pl.id=r.parking_lot_id WHERE pl.owner_id=?";
$params = array($user["id"]);
if ($lotId > 0) { $sql .= " AND r.parking_lot_id=?"; $params[] = $lotId; }
$sql .= " ORDER BY r.id DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = array();
foreach ($stmt->fetchAll() as $reservation) $rows[] = serialize_reservation($pdo, $reservation, "owner");
json_response($rows);
?>
