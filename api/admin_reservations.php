<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");
$stmt = $pdo->query("SELECT * FROM reservations ORDER BY id DESC");
$rows = array();
foreach ($stmt->fetchAll() as $reservation) $rows[] = serialize_reservation($pdo, $reservation, "admin");
json_response($rows);
?>
