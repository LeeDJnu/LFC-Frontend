<?php
require_once __DIR__ . "/common.php";
require_role($pdo, "owner");

error_response("오너 서명은 운전자 입차/출차 서명과 동시에 자동 처리됩니다.", 410);
?>
