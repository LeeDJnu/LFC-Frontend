<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);

$id = (int)($_GET["id"] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user["id"]]);
$vehicle = $stmt->fetch();
if (!$vehicle) error_response("Vehicle not found.", 404);

$stmt = $pdo->prepare("
    SELECT id FROM reservations
    WHERE vehicle_id = ? AND status IN ('pending','paid','checked_in')
    LIMIT 1
");
$stmt->execute([$id]);
if ($stmt->fetchColumn()) {
    error_response("A vehicle with an active reservation cannot be deleted.", 409);
}

$stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user["id"]]);

json_response(["ok" => true]);
?>
