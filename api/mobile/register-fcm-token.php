<?php
// api/mobile/register-fcm-token.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$fcmToken = sanitizeText($input['fcm_token'] ?? '', 255);
$deviceType = sanitizeText($input['device_type'] ?? 'android', 50);
$appVersion = sanitizeText($input['app_version'] ?? '1.0.0', 20);
$userId = $_SESSION['user_id'] ?? null;

if (empty($fcmToken) || empty($userId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'FCM Token and User ID required']);
    exit;
}

try {
    $existing = dbFetchOne("SELECT id FROM fcm_tokens WHERE fcm_token = ?", [$fcmToken], "s");
    if ($existing) {
        dbQuery("UPDATE fcm_tokens SET user_id = ?, device_type = ?, app_version = ?, updated_at = NOW() WHERE fcm_token = ?",
            [$userId, $deviceType, $appVersion, $fcmToken], "isss");
    } else {
        dbQuery("INSERT INTO fcm_tokens (user_id, fcm_token, device_type, app_version) VALUES (?, ?, ?, ?)",
            [$userId, $fcmToken, $deviceType, $appVersion], "isss");
    }
    echo json_encode(['status' => 'success', 'message' => 'FCM Token registered successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
