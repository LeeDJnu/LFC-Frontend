<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE user_id=? ORDER BY id DESC");
    $stmt->execute(array($user["id"]));
    $rows = array();
    foreach ($stmt->fetchAll() as $reservation) $rows[] = serialize_reservation($pdo, $reservation, "user");
    json_response($rows);
}

if ($method === "POST") {
    if ($user["role"] !== "user") error_response("사용자 계정만 예약할 수 있습니다.", 403);
    $body = json_body();
    $lotId = (int)(isset($body["parking_lot_id"]) ? $body["parking_lot_id"] : 0);
    $vehicleId = (int)(isset($body["vehicle_id"]) ? $body["vehicle_id"] : 0);
    $startTime = isset($body["start_time"]) ? $body["start_time"] : "";
    $driverPublicKey = isset($body["driver_temp_public_key"]) ? $body["driver_temp_public_key"] : "";
    $algorithm = isset($body["algorithm"]) ? $body["algorithm"] : "Ed25519";
    if (!$lotId || !$vehicleId || !$startTime) error_response("예약 정보가 부족합니다.", 400);
    if (!$driverPublicKey) error_response("예약별 운전자 임시 공개키가 필요합니다.", 400);

    $stmt = $pdo->prepare("SELECT * FROM parking_lots WHERE id=? AND is_active=1 AND supports_reservation=1");
    $stmt->execute(array($lotId));
    $lot = $stmt->fetch();
    if (!$lot) error_response("주차장을 찾을 수 없습니다.", 404);
    if ((int)$lot["available_spaces"] <= 0) error_response("현재 이용 가능한 주차면이 없습니다.", 409);

    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id=? AND user_id=?");
    $stmt->execute(array($vehicleId, $user["id"]));
    $vehicle = $stmt->fetch();
    if (!$vehicle) error_response("차량을 찾을 수 없습니다.", 404);

    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE vehicle_id=? AND status IN ('pending','paid','checked_in') LIMIT 1");
    $stmt->execute(array($vehicleId));
    if ($stmt->fetchColumn()) error_response("선택한 차량에 진행 중인 예약이 있습니다.", 409);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, parking_lot_id, vehicle_id, start_time, estimated_fee, final_fee, status, payment_status, payment_amount, created_at) VALUES (?, ?, ?, ?, 0, NULL, 'pending', 'requested', 0, NOW())");
    $stmt->execute(array($user["id"], $lotId, $vehicleId, normalize_datetime($startTime)));
    $reservationId = (int)$pdo->lastInsertId();
    $reservation = array("id"=>$reservationId, "user_id"=>$user["id"], "parking_lot_id"=>$lotId, "vehicle_id"=>$vehicleId);
    upsert_driver_public_key($pdo, $reservation, $driverPublicKey, $algorithm);
    $stmt = $pdo->prepare("UPDATE parking_lots SET available_spaces=GREATEST(available_spaces-1,0) WHERE id=?");
    $stmt->execute(array($lotId));
    $pdo->commit();

    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=?");
    $stmt->execute(array($reservationId));
    json_response(serialize_reservation($pdo, $stmt->fetch(), "user"));
}
error_response("지원하지 않는 요청입니다.", 405);
?>
