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
    if ($user["role"] !== "user") error_response("Only user accounts can make reservations.", 403);
    $body = json_body();
    $lotId = (int)(isset($body["parking_lot_id"]) ? $body["parking_lot_id"] : 0);
    $vehicleId = (int)(isset($body["vehicle_id"]) ? $body["vehicle_id"] : 0);
    $startTime = isset($body["start_time"]) ? $body["start_time"] : "";
    $driverPublicKey = isset($body["driver_temp_public_key"]) ? $body["driver_temp_public_key"] : "";
    $algorithm = isset($body["algorithm"]) ? $body["algorithm"] : "Ed25519";
    if (!$lotId || !$vehicleId || !$startTime) error_response("Reservation information is incomplete.", 400);
    if (!$driverPublicKey) error_response("A reservation-specific temporary driver public key is required.", 400);

    $stmt = $pdo->prepare("SELECT * FROM parking_lots WHERE id=? AND is_active=1 AND supports_reservation=1");
    $stmt->execute(array($lotId));
    $lot = $stmt->fetch();
    if (!$lot) error_response("Parking lot not found.", 404);
    if ((int)$lot["available_spaces"] <= 0) error_response("No parking spaces are currently available.", 409);

    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id=? AND user_id=?");
    $stmt->execute(array($vehicleId, $user["id"]));
    $vehicle = $stmt->fetch();
    if (!$vehicle) error_response("Vehicle not found.", 404);

    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE vehicle_id=? AND status IN ('pending','paid','checked_in') LIMIT 1");
    $stmt->execute(array($vehicleId));
    if ($stmt->fetchColumn()) error_response("The selected vehicle has an active reservation.", 409);

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
error_response("Unsupported request.", 405);
?>
