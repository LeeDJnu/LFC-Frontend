<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");

// 이전 버전에서 자동 생성된 데모 차량만 정리합니다.
// 이미 예약에 연결된 차량은 삭제하지 않습니다.
$targets = array(
    array("C1@example.com", "12가3456"),
    array("C2@example.com", "34나5678"),
    array("C3@example.com", "56다7890"),
    array("C4@example.com", "78라9012"),
    array("salah@example.com", "12가3456"),
    array("wirtz@example.com", "34나5678"),
    array("virgil@example.com", "56다7890"),
    array("szoboszlai@example.com", "78라9012")
);

$deleted = 0;
$kept = array();

foreach ($targets as $target) {
    $email = $target[0];
    $plate = $target[1];

    $stmt = $pdo->prepare("
        SELECT v.id
        FROM vehicles v
        JOIN users u ON u.id = v.user_id
        WHERE u.email = ? AND v.plate_number = ?
    ");
    $stmt->execute(array($email, $plate));
    $vehicleIds = $stmt->fetchAll();

    foreach ($vehicleIds as $row) {
        $vehicleId = (int)$row["id"];

        $check = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE vehicle_id = ?");
        $check->execute(array($vehicleId));
        $reservationCount = (int)$check->fetchColumn();

        if ($reservationCount > 0) {
            $kept[] = array(
                "email" => $email,
                "plate_number" => $plate,
                "reason" => "예약 기록에 연결되어 있어 삭제하지 않음"
            );
            continue;
        }

        $delete = $pdo->prepare("DELETE FROM vehicles WHERE id = ?");
        $delete->execute(array($vehicleId));
        $deleted += $delete->rowCount();
    }
}

json_response(array(
    "ok" => true,
    "message" => "자동 생성 데모 차량 정리 완료",
    "deleted" => $deleted,
    "kept" => $kept
));
?>
