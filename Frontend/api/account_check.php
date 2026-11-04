<?php
require_once __DIR__ . "/common.php";
$counts = ensure_database_installed($pdo);

$emails = array(
    "admin@example.com",
    "C1@example.com", "C2@example.com", "C3@example.com", "C4@example.com",
    "H1@example.com", "H2@example.com", "H3@example.com", "H4@example.com", "H5@example.com"
);

$result = array();
foreach ($emails as $email) {
    $stmt = $pdo->prepare("SELECT id, email, name, role FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1");
    $stmt->execute(array($email));
    $row = $stmt->fetch();
    $result[] = array(
        "email" => $email,
        "exists" => (bool)$row,
        "id" => $row ? (int)$row["id"] : null,
        "name" => $row ? $row["name"] : null,
        "role" => $row ? $row["role"] : null
    );
}

json_response(array(
    "ok" => true,
    "counts" => $counts,
    "accounts" => $result
));
?>
