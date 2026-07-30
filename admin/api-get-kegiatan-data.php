<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';
requireLogin();

header('Content-Type: application/json');

$nama_acara = isset($_GET['nama_acara']) ? trim($_GET['nama_acara']) : '';
$periode_id = getUserPeriode();

if (empty($nama_acara)) {
    echo json_encode(['status' => 'error', 'message' => 'Nama acara tidak diberikan.']);
    exit;
}

$response = [
    'status' => 'success',
    'rundown' => null,
    'logistik' => null
];

// Fetch Rundown
$rundown = dbFetchOne("SELECT * FROM arsip_rundown WHERE nama_acara = ? AND periode_id = ? ORDER BY id DESC LIMIT 1", [$nama_acara, $periode_id]);
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

// Fetch Logistik
$logistik = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE nama_acara = ? AND periode_id = ? ORDER BY id DESC LIMIT 1", [$nama_acara, $periode_id]);
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

echo json_encode($response);
exit;
