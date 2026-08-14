<?php
require_once __DIR__ . "/common.php";
$user = require_user($pdo);
json_response(public_user($user));
?>
