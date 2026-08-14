<?php
require_once __DIR__ . "/common.php";

$user = require_user($pdo);
$id = (int)(isset($_GET["id"]) ? $_GET["id"] : 0);
$action = isset($_GET["action"]) ? $_GET["action"] : "";
$body = json_body();

$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=? AND user_id=?");
$stmt->execute(array($id, $user["id"]));
$reservation = $stmt->fetch();

if (!$reservation) error_response("예약을 찾을 수 없습니다.", 404);

if ($action === "cancel") {
    if (!in_array($reservation["status"], array("pending","paid"), true) || !empty($reservation["driver_check_in_signature_value"])) {
        error_response("입차 전 예약만 취소할 수 있습니다.", 409);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE reservations SET status='cancelled', payment_status='refunded', payment_amount=0, final_fee=0 WHERE id=?");
    $stmt->execute(array($id));

    $stmt = $pdo->prepare("UPDATE parking_lots SET available_spaces=LEAST(total_spaces, available_spaces+1) WHERE id=?");
    $stmt->execute(array($reservation["parking_lot_id"]));
    $pdo->commit();

    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=?");
    $stmt->execute(array($id));
    json_response(serialize_reservation($pdo, $stmt->fetch(), "user"));
}

if ($action === "check-in") {
    if (!in_array($reservation["status"], array("pending","paid"), true)) {
        error_response("입차 대기 상태의 예약만 입차할 수 있습니다.", 409);
    }

    if (!empty($reservation["driver_check_in_signature_value"])) {
        error_response("이미 입차 서명이 완료되었습니다.", 409);
    }

    $signedAt = isset($body["signed_at"]) ? $body["signed_at"] : date("c");
    $signatureImage = isset($body["signature_data_url"]) ? $body["signature_data_url"] : "";

    $driverVerified = verify_signature_payload($pdo, $reservation, $body, "driver", "check-in");
    $ownerAuto = create_auto_owner_signature($pdo, $reservation, "check-in", $signedAt);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE reservations
        SET
          status='checked_in',
          check_in_at=NOW(),
          check_in_signature=?,
          check_in_signed_message=?,
          check_in_signed_at=?,
          check_in_key_id=NULL,
          driver_check_in_signature=?,
          driver_check_in_signed_message=?,
          driver_check_in_signature_value=?,
          driver_check_in_signed_at=?,
          driver_check_in_verified_at=NOW(),
          owner_check_in_signature=?,
          owner_check_in_signed_message=?,
          owner_check_in_signature_value=?,
          owner_check_in_signed_at=?,
          owner_check_in_verified_at=NOW()
        WHERE id=?
    ");
    $stmt->execute(array(
        $signatureImage,
        $driverVerified["signed_message"],
        normalize_datetime($signedAt),
        $signatureImage,
        $driverVerified["signed_message"],
        $driverVerified["signature_value"],
        normalize_datetime($signedAt),
        "AUTO_OWNER_CONFIRMATION",
        $ownerAuto["signed_message"],
        $ownerAuto["signature_value"],
        normalize_datetime($ownerAuto["signed_at"]),
        $id
    ));

    save_signature_record($pdo, $id, "driver", "check-in", isset($body["algorithm"]) ? $body["algorithm"] : "Ed25519", $driverVerified["public_key"], $driverVerified["signed_message"], $driverVerified["signature_value"], $signedAt);
    save_signature_record($pdo, $id, "owner", "check-in", $ownerAuto["algorithm"], $ownerAuto["public_key"], $ownerAuto["signed_message"], $ownerAuto["signature_value"], $ownerAuto["signed_at"]);

    $pdo->commit();

    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=?");
    $stmt->execute(array($id));
    json_response(serialize_reservation($pdo, $stmt->fetch(), "user"));
}

if ($action === "check-out") {
    if ($reservation["status"] !== "checked_in") {
        error_response("주차 중인 예약만 출차할 수 있습니다.", 409);
    }

    if (!empty($reservation["driver_check_out_signature_value"])) {
        error_response("이미 출차 서명이 완료되었습니다.", 409);
    }

    $signedAt = isset($body["signed_at"]) ? $body["signed_at"] : date("c");
    $signatureImage = isset($body["signature_data_url"]) ? $body["signature_data_url"] : "";

    $driverVerified = verify_signature_payload($pdo, $reservation, $body, "driver", "check-out");
    $ownerAuto = create_auto_owner_signature($pdo, $reservation, "check-out", $signedAt);

    $finalFee = 3000;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE reservations
        SET
          status='completed',
          check_out_at=NOW(),
          check_out_signature=?,
          check_out_signed_message=?,
          check_out_signed_at=?,
          check_out_key_id=NULL,
          driver_check_out_signature=?,
          driver_check_out_signed_message=?,
          driver_check_out_signature_value=?,
          driver_check_out_signed_at=?,
          driver_check_out_verified_at=NOW(),
          owner_check_out_signature=?,
          owner_check_out_signed_message=?,
          owner_check_out_signature_value=?,
          owner_check_out_signed_at=?,
          owner_check_out_verified_at=NOW(),
          final_fee=?,
          payment_status='requested',
          payment_amount=?
        WHERE id=?
    ");
    $stmt->execute(array(
        $signatureImage,
        $driverVerified["signed_message"],
        normalize_datetime($signedAt),
        $signatureImage,
        $driverVerified["signed_message"],
        $driverVerified["signature_value"],
        normalize_datetime($signedAt),
        "AUTO_OWNER_CONFIRMATION",
        $ownerAuto["signed_message"],
        $ownerAuto["signature_value"],
        normalize_datetime($ownerAuto["signed_at"]),
        $finalFee,
        $finalFee,
        $id
    ));

    $stmt = $pdo->prepare("UPDATE parking_lots SET available_spaces=LEAST(total_spaces, available_spaces+1) WHERE id=?");
    $stmt->execute(array($reservation["parking_lot_id"]));

    save_signature_record($pdo, $id, "driver", "check-out", isset($body["algorithm"]) ? $body["algorithm"] : "Ed25519", $driverVerified["public_key"], $driverVerified["signed_message"], $driverVerified["signature_value"], $signedAt);
    save_signature_record($pdo, $id, "owner", "check-out", $ownerAuto["algorithm"], $ownerAuto["public_key"], $ownerAuto["signed_message"], $ownerAuto["signature_value"], $ownerAuto["signed_at"]);

    $pdo->commit();

    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=?");
    $stmt->execute(array($id));
    json_response(serialize_reservation($pdo, $stmt->fetch(), "user"));
}

if ($action === "pay") {
    if ($reservation["status"] !== "completed" || empty($reservation["owner_check_out_signature_value"])) {
        error_response("출차 완료 후 결제할 수 있습니다.", 409);
    }

    $amount = (int)(isset($reservation["final_fee"]) ? $reservation["final_fee"] : 3000);
    $stmt = $pdo->prepare("UPDATE reservations SET payment_status='paid', payment_amount=?, paid_at=NOW() WHERE id=?");
    $stmt->execute(array($amount, $id));

    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id=?");
    $stmt->execute(array($id));
    json_response(serialize_reservation($pdo, $stmt->fetch(), "user"));
}

error_response("지원하지 않는 예약 처리입니다.", 405);
?>
