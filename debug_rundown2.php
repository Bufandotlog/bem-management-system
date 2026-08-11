<?php
require_once __DIR__ . '/includes/functions.php';
$rundown = dbFetchOne("SELECT * FROM arsip_rundown WHERE id = 9");
print_r(json_decode($rundown['rundown_json'], true));
?>
