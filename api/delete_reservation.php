<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);
$id = (int)(isset($_GET["id"]) ? $_GET["id"] : 0);
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND user_id = ?");
$stmt->execute(array($id, $user["id"]));
$reservation = $stmt->fetch();
if (!$reservation) error_response("예약을 찾을 수 없습니다.", 404);
if ($reservation["status"] !== "cancelled") error_response("취소한 예약만 삭제할 수 있습니다.", 409);
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM reservation_signatures WHERE reservation_id=?")->execute(array($id));
$pdo->prepare("DELETE FROM owner_reservation_public_keys WHERE reservation_id=?")->execute(array($id));
$pdo->prepare("DELETE FROM reservation_signature_keys WHERE reservation_id=?")->execute(array($id));
$pdo->prepare("DELETE FROM reservations WHERE id=? AND user_id=?")->execute(array($id, $user["id"]));
$pdo->commit();
json_response(array("ok"=>true));
?>
