<?php
require_once __DIR__ . '/config/database.php';
$cols = dbFetchAll("SHOW COLUMNS FROM arsip_dokumentasi");
print_r($cols);
