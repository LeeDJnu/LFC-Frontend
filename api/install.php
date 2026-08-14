<?php
require_once __DIR__ . "/common.php";
$counts = ensure_database_installed($pdo);
json_response(array("ok"=>true, "message"=>"MySQL 테이블 생성 및 Ed25519 기반 양측 서명 구조 설치 완료", "counts"=>$counts));
?>
