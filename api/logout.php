<?php
require_once __DIR__ . "/common.php";
$_SESSION = array();
session_destroy();
json_response(array("ok"=>true));
?>
