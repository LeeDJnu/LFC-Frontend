<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");

remove_old_key_digest_columns($pdo);
remove_old_vehicle_alias_column($pdo);

$tables = array("reservation_signature_keys", "owner_reservation_public_keys", "reservation_signatures", "vehicles");
$result = array();

foreach ($tables as $table) {
    $columns = array();
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . $table . "`");
        foreach ($stmt->fetchAll() as $row) {
            $columns[] = $row["Field"];
        }
    } catch (Exception $ignored) {}
    $result[$table] = $columns;
}

json_response(array(
    "ok" => true,
    "message" => "예약/서명 DB 스키마 정리 완료",
    "columns" => $result
));
?>
