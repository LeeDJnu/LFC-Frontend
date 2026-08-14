<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);
$id = (int)(isset($_GET["id"]) ? $_GET["id"] : 0);
$body = json_body();
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=? AND user_id=?");
$stmt->execute(array($id, $user["id"]));
$reservation = $stmt->fetch();
if (!$reservation) error_response("예약을 찾을 수 없습니다.", 404);
if (!empty($reservation["driver_check_in_signature_value"]) || !empty($reservation["driver_check_out_signature_value"])) error_response("이미 운전자 서명이 있는 예약의 공개키는 변경할 수 없습니다.", 409);
upsert_driver_public_key($pdo, $reservation, isset($body["driver_temp_public_key"])?$body["driver_temp_public_key"]:"", isset($body["algorithm"])?$body["algorithm"]:"Ed25519");
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=?");
$stmt->execute(array($id));
json_response(serialize_reservation($pdo, $stmt->fetch(), "user"));
?>
