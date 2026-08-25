<?php
// api/notifications.php - Endpoint AJAX Notifikasi Admin
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../includes/functions.php';

// Pastikan user sudah login sebagai admin / pengurus
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['admin_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'fetch';

if ($action === 'fetch') {
    // 1. Ambil jumlah unread
    $unreadRow = dbFetchOne("SELECT COUNT(*) as total FROM notifikasi WHERE user_id = ? AND (is_read = 0 OR is_read IS NULL)", [$userId]);
    $unreadCount = (int)($unreadRow['total'] ?? 0);

    // 2. Ambil 15 notifikasi terbaru
    $list = dbFetchAll(
        "SELECT id, judul, pesan, link, tipe, is_read, created_at FROM notifikasi WHERE user_id = ? ORDER BY id DESC LIMIT 15",
        [$userId]
    );

    // Format selisih waktu
    foreach ($list as &$item) {
        $timeAgo = '';
        if (!empty($item['created_at'])) {
            $ts = strtotime($item['created_at']);
            $diff = time() - $ts;
            if ($diff < 60) {
                $timeAgo = 'Baru saja';
            } elseif ($diff < 3600) {
                $timeAgo = floor($diff / 60) . ' mnt lalu';
            } elseif ($diff < 86400) {
                $timeAgo = floor($diff / 3600) . ' jam lalu';
            } else {
                $timeAgo = floor($diff / 86400) . ' hr lalu';
            }
        }
        $item['time_ago'] = $timeAgo;
        $item['judul'] = !empty($item['judul']) ? $item['judul'] : 'Notifikasi BEM';
    }

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $list
    ]);
    exit;
}

if ($action === 'mark_read') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $all = isset($_POST['all']) && $_POST['all'] == '1';

    if ($all) {
        dbQuery("UPDATE notifikasi SET is_read = 1 WHERE user_id = ?", [$userId]);
    } elseif ($id > 0) {
        dbQuery("UPDATE notifikasi SET is_read = 1 WHERE id = ? AND user_id = ?", [$id, $userId]);
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        dbQuery("DELETE FROM notifikasi WHERE id = ? AND user_id = ?", [$id, $userId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'clear_all') {
    dbQuery("DELETE FROM notifikasi WHERE user_id = ?", [$userId]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
