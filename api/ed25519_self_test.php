<?php
require_once __DIR__ . "/common.php";
if (!function_exists("sodium_crypto_sign_keypair")) {
    error_response("PHP sodium 확장이 비활성화되어 있습니다.", 500);
}
$message = "ed25519-self-test|" . date("c");
$keypair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($keypair);
$secretKey = sodium_crypto_sign_secretkey($keypair);
$signature = sodium_crypto_sign_detached($message, $secretKey);
$ok = sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
json_response(array(
    "ok" => $ok,
    "algorithm" => "Ed25519",
    "public_key_bytes" => strlen($publicKey),
    "signature_bytes" => strlen($signature)
));
?>
