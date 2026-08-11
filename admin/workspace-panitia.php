<?php
// admin/workspace-panitia.php
// Forwarder file to ensure admin/buat-panitia.php remains the primary single master file
require_once __DIR__ . '/../includes/functions.php';

$kegiatan_id = isset($_GET['kegiatan_id']) ? (int)$_GET['kegiatan_id'] : 0;
if ($kegiatan_id > 0) {
    redirect("admin/buat-panitia.php?kegiatan_id={$kegiatan_id}");
} else {
    redirect("admin/buat-panitia.php");
}
exit();
