<?php
require_once __DIR__ . "/common.php";
ensure_database_installed($pdo);
$body = json_body();
$email = trim(isset($body["email"]) ? $body["email"] : "");
$password = isset($body["password"]) ? $body["password"] : "";

$aliases = array(
    "c1@example.com" => "C1@example.com",
    "c2@example.com" => "C2@example.com",
    "c3@example.com" => "C3@example.com",
    "c4@example.com" => "C4@example.com",
    "h1@example.com" => "H1@example.com",
    "h2@example.com" => "H2@example.com",
    "h3@example.com" => "H3@example.com",
    "h4@example.com" => "H4@example.com",
    "h5@example.com" => "H5@example.com",
    "admin@example.com" => "admin@example.com"
);
$key = strtolower($email);
$canonical = isset($aliases[$key]) ? $aliases[$key] : $email;

$stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
$stmt->execute(array($canonical));
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["password_hash"])) {
    error_response("이메일 또는 비밀번호가 올바르지 않습니다.", 401);
}

$_SESSION["user_id"] = (int)$user["id"];
json_response(array("access_token"=>"php-session", "token_type"=>"bearer", "temporary_key_id"=>null, "temporary_private_key"=>null));
?>
