<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);

$id = (int)($_GET["id"] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user["id"]]);
$vehicle = $stmt->fetch();
if (!$vehicle) error_response("차량을 찾을 수 없습니다.", 404);

$stmt = $pdo->prepare("
    SELECT id FROM reservations
    WHERE vehicle_id = ? AND status IN ('pending','paid','checked_in')
    LIMIT 1
");
$stmt->execute([$id]);
if ($stmt->fetchColumn()) {
    error_response("진행 중인 예약이 있는 차량은 삭제할 수 없습니다.", 409);
}

$stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user["id"]]);

json_response(["ok" => true]);
?>
