<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);
$id = (int)(isset($_GET["id"]) ? $_GET["id"] : 0);
$body = json_body();
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=? AND user_id=?");
$stmt->execute(array($id, $user["id"]));
$reservation = $stmt->fetch();
if (!$reservation) error_response("Reservation not found.", 404);
if (!empty($reservation["driver_check_in_signature_value"]) || !empty($reservation["driver_check_out_signature_value"])) error_response("The public key cannot be changed for a reservation that already has a driver's signature.", 409);
upsert_driver_public_key($pdo, $reservation, isset($body["driver_temp_public_key"])?$body["driver_temp_public_key"]:"", isset($body["algorithm"])?$body["algorithm"]:"Ed25519");
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=?");
$stmt->execute(array($id));
json_response(serialize_reservation($pdo, $stmt->fetch(), "user"));
?>
