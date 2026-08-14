<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");
$id = (int)(isset($_GET["id"]) ? $_GET["id"] : 0);
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
$stmt->execute(array($id));
$reservation = $stmt->fetch();
if (!$reservation) error_response("예약을 찾을 수 없습니다.", 404);
if (!($reservation["status"] === "completed" && $reservation["payment_status"] === "paid")) error_response("결제 완료된 이용 기록만 삭제할 수 있습니다.", 409);
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM reservation_signatures WHERE reservation_id=?")->execute(array($id));
$pdo->prepare("DELETE FROM owner_reservation_public_keys WHERE reservation_id=?")->execute(array($id));
$pdo->prepare("DELETE FROM reservation_signature_keys WHERE reservation_id=?")->execute(array($id));
$pdo->prepare("DELETE FROM reservations WHERE id=?")->execute(array($id));
$pdo->commit();
json_response(array("ok"=>true));
?>
