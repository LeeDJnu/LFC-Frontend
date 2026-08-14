<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "admin");

$stmt = $pdo->query("
    SELECT id, email, name, phone, role, created_at
    FROM users
    ORDER BY id ASC
");

$rows = array_map(function($row) {
    return [
        "id" => (int)$row["id"],
        "email" => $row["email"],
        "name" => $row["name"],
        "phone" => $row["phone"],
        "role" => $row["role"],
        "created_at" => $row["created_at"],
    ];
}, $stmt->fetchAll());

json_response($rows);
?>
