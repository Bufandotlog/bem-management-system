<?php
// api/save-logistik-readiness.php
// Endpoint for toggling logistik item readiness state on the dashboard
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../admin/core/config.php';
requireLogin();

header('Content-Type: application/json');

$respond = static function (int $statusCode, array $response): void {
    http_response_code($statusCode);
    echo json_encode($response);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['status' => 'error', 'message' => 'Metode tidak diizinkan.']);
}

if (!csrfVerify()) {
    $respond(403, ['status' => 'error', 'message' => 'Token CSRF tidak valid atau kedaluwarsa.']);
}

$lampiran_id = filter_var($_POST['lampiran_id'] ?? null, FILTER_VALIDATE_INT);
$kegiatan_id = filter_var($_POST['kegiatan_id'] ?? null, FILTER_VALIDATE_INT);
$status = filter_var($_POST['status'] ?? null, FILTER_VALIDATE_INT);
$item_id = trim((string)($_POST['item_id'] ?? ''));
$periode_id = getUserPeriode();

if (
    !$lampiran_id || !$kegiatan_id || $item_id === ''
    || !in_array($status, [0, 1], true)
) {
    $respond(400, ['status' => 'error', 'message' => 'Parameter permintaan tidak valid.']);
}

$kegiatan = dbFetchOne(
    "SELECT id, nama_kegiatan, status
     FROM kegiatan
     WHERE id = ? AND periode_id = ?",
    [(int)$kegiatan_id, $periode_id],
    "ii"
);
if (!$kegiatan) {
    $respond(404, ['status' => 'error', 'message' => 'Kegiatan tidak ditemukan.']);
}

$system_role = strtolower(trim((string)($_SESSION['admin_role'] ?? '')));
$can_manage_all = in_array($system_role, ['superadmin', 'admin'], true)
    || isSekretaris()
    || !empty($_SESSION['admin_can_access_all']);
$event_role = getEventRole($_SESSION['admin_id'] ?? 0, (int)$kegiatan_id);

if (
    !$can_manage_all
    && (
        $kegiatan['status'] === 'selesai'
        || !in_array($event_role, ['ketuplat', 'sie_logistik'], true)
    )
) {
    $respond(403, ['status' => 'error', 'message' => 'Anda tidak memiliki akses logistik untuk kegiatan ini.']);
}

$row = dbFetchOne(
    "SELECT id, barang_json, readiness_json
     FROM lampiran_pinjam
     WHERE id = ? AND periode_id = ? AND kegiatan_id = ?
     LIMIT 1",
    [(int)$lampiran_id, $periode_id, (int)$kegiatan_id],
    "iii"
);

if (!$row) {
    $matching_activity_count = dbFetchOne(
        "SELECT COUNT(*) AS total
         FROM kegiatan
         WHERE periode_id = ? AND LOWER(TRIM(nama_kegiatan)) = LOWER(TRIM(?))",
        [$periode_id, $kegiatan['nama_kegiatan']],
        "is"
    );
    if ((int)($matching_activity_count['total'] ?? 0) === 1) {
        $row = dbFetchOne(
            "SELECT id, barang_json, readiness_json
             FROM lampiran_pinjam
             WHERE id = ? AND periode_id = ? AND kegiatan_id IS NULL
               AND LOWER(TRIM(nama_acara)) = LOWER(TRIM(?))
             LIMIT 1",
            [(int)$lampiran_id, $periode_id, $kegiatan['nama_kegiatan']],
            "iis"
        );
    }
}

if (!$row) {
    $respond(404, ['status' => 'error', 'message' => 'Data logistik kegiatan tidak ditemukan.']);
}

$items = json_decode((string)$row['barang_json'], true);
if (!is_array($items)) {
    $respond(422, ['status' => 'error', 'message' => 'Daftar barang logistik tidak valid.']);
}

$item_exists = false;
foreach ($items as $item) {
    if (is_array($item) && isset($item['id']) && (string)$item['id'] === $item_id) {
        $item_exists = true;
        break;
    }
}
if (!$item_exists) {
    $respond(400, ['status' => 'error', 'message' => 'Item tidak termasuk dalam kegiatan ini.']);
}

$readiness = [];
if (!empty($row['readiness_json'])) {
    $decoded_readiness = json_decode((string)$row['readiness_json'], true);
    if (!is_array($decoded_readiness)) {
        $respond(422, ['status' => 'error', 'message' => 'Data kesiapan logistik tidak valid.']);
    }
    $readiness = $decoded_readiness;
}

$readiness[$item_id] = (int)$status;
$updated_json = json_encode($readiness);
if ($updated_json === false) {
    $respond(500, ['status' => 'error', 'message' => 'Data kesiapan logistik gagal diproses.']);
}

dbQuery(
    "UPDATE lampiran_pinjam SET readiness_json = ?
     WHERE id = ? AND periode_id = ? AND (kegiatan_id = ? OR kegiatan_id IS NULL)",
    [$updated_json, (int)$lampiran_id, $periode_id, (int)$kegiatan_id],
    "siii"
);

$total_count = count($items);
$ready_count = 0;
foreach ($items as $item) {
    $id = is_array($item) && isset($item['id']) ? (string)$item['id'] : '';
    if ($id !== '' && !empty($readiness[$id])) {
        $ready_count++;
    }
}

$respond(200, [
    'status' => 'success',
    'item_id' => $item_id,
    'ready_count' => $ready_count,
    'total_count' => $total_count,
    'percentage' => $total_count > 0 ? round(($ready_count / $total_count) * 100) : 0
]);
