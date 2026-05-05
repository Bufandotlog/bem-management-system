<?php
// admin/catat-surat-masuk.php - REDIRECT TO NEW CONSOLIDATED ARCHIVE PAGE
require_once __DIR__ . '/config.php';
header("Location: arsip-manual.php" . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit();
