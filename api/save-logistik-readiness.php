<?php
// api/save-logistik-readiness.php
// Endpoint for toggling logistik item readiness state on the dashboard
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../admin/core/config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
    exit;
}

$lampiran_id = isset($_POST['lampiran_id']) ? (int)$_POST['lampiran_id'] : 0;
$item_id     = isset($_POST['item_id']) ? trim($_POST['item_id']) : '';
$status      = isset($_POST['status']) ? (int)$_POST['status'] : 0;
$periode_id  = getUserPeriode();

if (!$lampiran_id || empty($item_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameter']);
    exit;
}

$row = dbFetchOne("SELECT id, barang_json, readiness_json FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$lampiran_id, $periode_id]);
if (!$row) {
    echo json_encode(['status' => 'error', 'message' => 'Data logistik tidak ditemukan']);
    exit;
}

$readiness = !empty($row['readiness_json']) ? (json_decode($row['readiness_json'], true) ?: []) : [];
$readiness[$item_id] = $status ? 1 : 0;

$updated_json = json_encode($readiness);
dbQuery("UPDATE lampiran_pinjam SET readiness_json = ? WHERE id = ?", [$updated_json, $lampiran_id]);

// Calculate new percentage
$items = !empty($row['barang_json']) ? (json_decode($row['barang_json'], true) ?: []) : [];
$total_count = count($items);
$ready_count = 0;

foreach ($items as $it) {
    $iid = $it['id'] ?? '';
    if (!empty($iid) && !empty($readiness[$iid])) {
        $ready_count++;
    }
}

$percentage = $total_count > 0 ? round(($ready_count / $total_count) * 100) : 0;

echo json_encode([
    'status' => 'success',
    'item_id' => $item_id,
    'ready_count' => $ready_count,
    'total_count' => $total_count,
    'percentage' => $percentage
]);
exit;
