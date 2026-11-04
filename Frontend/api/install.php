<?php
require_once __DIR__ . "/common.php";
$counts = ensure_database_installed($pdo);
json_response(array("ok"=>true, "message"=>"MySQL tables and the Ed25519-based dual-signature structure have been installed.", "counts"=>$counts));
?>
