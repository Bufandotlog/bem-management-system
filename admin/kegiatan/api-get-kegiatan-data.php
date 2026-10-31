<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../core/config.php';
requireLogin();

header('Content-Type: application/json');

$nama_acara = isset($_GET['nama_acara']) ? trim($_GET['nama_acara']) : '';
$kegiatan_id = isset($_GET['kegiatan_id']) ? filter_var($_GET['kegiatan_id'], FILTER_VALIDATE_INT) : 0;
$kegiatan_id = $kegiatan_id !== false ? (int)$kegiatan_id : 0;
$periode_id = getUserPeriode();

if ($kegiatan_id <= 0 && empty($nama_acara)) {
    echo json_encode(['status' => 'error', 'message' => 'Kegiatan tidak diberikan.']);
    exit;
}

$kegiatan = null;
if ($kegiatan_id > 0) {
    $kegiatan = dbFetchOne("SELECT id, nama_kegiatan FROM kegiatan WHERE id = ? AND periode_id = ?", [$kegiatan_id, $periode_id], "ii");
    if (!$kegiatan) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Kegiatan tidak ditemukan pada periode aktif.']);
        exit;
    }
    $nama_acara = $kegiatan['nama_kegiatan'];
}

$response = [
    'status' => 'success',
    'rundown' => null,
    'logistik' => null,
    'dokumentasi' => null
];

// Fetch Rundown: prioritaskan relasi kegiatan_id jika ada, fallback ke pencarian nama
$rundown = $kegiatan_id > 0
    ? dbFetchOne("SELECT * FROM arsip_rundown WHERE kegiatan_id = ? AND periode_id = ? ORDER BY id DESC LIMIT 1", [$kegiatan_id, $periode_id], "ii")
    : null;
if (!$rundown) {
    $rundown = dbFetchOne(
        "SELECT * FROM arsip_rundown WHERE LOWER(TRIM(nama_acara)) = LOWER(TRIM(?)) AND periode_id = ?"
        . ($kegiatan_id > 0 ? " AND kegiatan_id IS NULL" : "")
        . " ORDER BY id DESC LIMIT 1",
        [$nama_acara, $periode_id],
        "si"
    );
}
if ($rundown) {
    $rundown_json = json_decode($rundown['rundown_json'], true);
    
    // Convert rundown structure into a flat list of text (rincian_kegiatan)
    $rincian = [];
    if ($rundown_json) {
        foreach ($rundown_json as $day) {
            if (isset($day['items'])) {
                foreach ($day['items'] as $item) {
                    if (!empty($item['acara'])) {
                        $rincian[] = $item['acara'];
                    }
                }
            }
        }
    }
    
    $response['rundown'] = [
        'tanggal_mulai' => $rundown['tanggal_mulai'],
        'rincian' => array_values(array_unique($rincian))
    ];
}

$matching_activities = $nama_acara !== ''
    ? dbFetchAll(
        "SELECT id FROM kegiatan WHERE LOWER(TRIM(nama_kegiatan)) = LOWER(TRIM(?)) AND periode_id = ? LIMIT 2",
        [$nama_acara, $periode_id],
        "si"
    )
    : [];
$unique_activity_id = count($matching_activities) === 1 ? (int)$matching_activities[0]['id'] : 0;
$logistik_activity_id = $kegiatan_id > 0 ? $kegiatan_id : $unique_activity_id;

// Prefer the activity link; only use legacy name matching when unambiguous.
$logistik = $logistik_activity_id > 0
    ? dbFetchOne("SELECT * FROM lampiran_pinjam WHERE kegiatan_id = ? AND periode_id = ? ORDER BY id DESC LIMIT 1", [$logistik_activity_id, $periode_id], "ii")
    : null;
if (!$logistik && count($matching_activities) <= 1) {
    $logistik = dbFetchOne(
        "SELECT * FROM lampiran_pinjam WHERE kegiatan_id IS NULL AND LOWER(TRIM(nama_acara)) = LOWER(TRIM(?)) AND periode_id = ? ORDER BY id DESC LIMIT 1",
        [$nama_acara, $periode_id]
    );
}
if ($logistik) {
    $barang_json = json_decode($logistik['barang_json'], true);
    $barang_list = [];
    if ($barang_json) {
        foreach ($barang_json as $b) {
            if (!empty($b['nama'])) {
                $barang_list[] = $b['nama'] . ' (' . $b['qty'] . ')';
            }
        }
    }
    $response['logistik'] = [
        'tanggal_kegiatan' => $logistik['tanggal_kegiatan'],
        'barang' => $barang_list
    ];
}

// Fetch Dokumentasi
$dok_kegiatan = $kegiatan_id > 0
    ? $kegiatan
    : dbFetchOne("SELECT id FROM kegiatan WHERE LOWER(TRIM(nama_kegiatan)) = LOWER(TRIM(?)) AND periode_id = ? LIMIT 1", [$nama_acara, $periode_id], "si");
if ($dok_kegiatan) {
    $dokumentasi = dbFetchOne("SELECT dokumentasi_json FROM arsip_dokumentasi WHERE kegiatan_id = ? LIMIT 1", [$dok_kegiatan['id']]);
    if ($dokumentasi && !empty($dokumentasi['dokumentasi_json'])) {
        $docs_arr = json_decode($dokumentasi['dokumentasi_json'], true) ?: [];
        $docs = [];
        foreach ($docs_arr as $d) {
            if (!empty($d['foto'])) {
                $docs[] = [
                    'image' => $d['foto'],
                    'image_url' => uploadUrl($d['foto']),
                    'caption' => $d['caption'] ?? ''
                ];
            }
        }
        if (!empty($docs)) {
            $response['dokumentasi'] = $docs;
        }
    }
}

echo json_encode($response);
exit;
