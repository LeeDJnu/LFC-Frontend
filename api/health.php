<?php
require_once __DIR__ . "/common.php";
$counts = ensure_database_installed($pdo);
remove_old_key_digest_columns($pdo);
remove_old_vehicle_alias_column($pdo);
json_response(array(
    "ok"=>true,
    "php_version"=>PHP_VERSION,
    "db_connected"=>true,
    "sodium"=>function_exists("sodium_crypto_sign_verify_detached"),
    "counts"=>$counts
));
?>
