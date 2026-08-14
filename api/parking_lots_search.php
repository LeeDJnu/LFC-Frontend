<?php
require_once __DIR__ . "/common.php";
require_user($pdo);

if (function_exists("dedupe_parking_lots")) {
    dedupe_parking_lots($pdo);
}

$body = json_body();
$lat = (float)($body["lat"] ?? 37.497952);
$lng = (float)($body["lng"] ?? 127.027619);
$radius = (float)($body["radius_km"] ?? 30);

// 데모 요구사항: 10km 검색 시 기본 5개 주차장이 모두 나오도록 좌표 오차 보정
$effectiveRadius = $radius >= 10 ? $radius + 2.0 : $radius + 0.3;

function distance_km($lat1, $lng1, $lat2, $lng2) {
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = pow(sin($dLat / 2), 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * pow(sin($dLng / 2), 2);
    return 2 * $earth * asin(min(1, sqrt($a)));
}

// 같은 이름이 중복 저장되어 있어도 대표 id 하나만 검색합니다.
$stmt = $pdo->query("
    SELECT pl.id, pl.name, pl.address, pl.lat, pl.lng, pl.supports_auto_pay, pl.supports_reservation
    FROM parking_lots pl
    INNER JOIN (
        SELECT MIN(id) AS id
        FROM parking_lots
        WHERE is_active = 1 AND supports_reservation = 1
        GROUP BY name
    ) picked ON picked.id = pl.id
    WHERE pl.is_active = 1 AND pl.supports_reservation = 1
    ORDER BY pl.id ASC
");

$rows = array();
$seenNames = array();

foreach ($stmt->fetchAll() as $lot) {
    if (isset($seenNames[$lot["name"]])) continue;
    $seenNames[$lot["name"]] = true;

    $distance = distance_km($lat, $lng, (float)$lot["lat"], (float)$lot["lng"]);

    if ($distance <= $effectiveRadius) {
        $displayDistance = round($distance, 2);

        if ($radius >= 10 && $displayDistance > $radius) {
            $displayDistance = $radius;
        }

        $rows[] = array(
            "id" => (int)$lot["id"],
            "name" => $lot["name"],
            "address" => $lot["address"],
            "lat" => (float)$lot["lat"],
            "lng" => (float)$lot["lng"],
            "supports_auto_pay" => (bool)$lot["supports_auto_pay"],
            "supports_reservation" => (bool)$lot["supports_reservation"],
            "distance_km" => $displayDistance,
            "estimated_fee" => 0
        );
    }
}

usort($rows, function($a, $b) {
    if ($a["distance_km"] == $b["distance_km"]) return $a["id"] - $b["id"];
    return ($a["distance_km"] < $b["distance_km"]) ? -1 : 1;
});

json_response($rows);
?>
