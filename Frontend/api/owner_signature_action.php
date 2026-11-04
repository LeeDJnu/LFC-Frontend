<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "owner");

error_response("The owner signature is processed automatically together with the driver's check-in/check-out signature.", 410);
?>
