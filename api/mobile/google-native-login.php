<?php
// api/mobile/google-native-login.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$input = json_decode(file_get_contents('php://input'), true);
$idToken = trim($input['id_token'] ?? '');

if (empty($idToken)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id_token is required']);
    exit;
}

// Verifikasi Google id_token menggunakan Google tokeninfo API
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$ch = curl_init($verifyUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid Google ID token']);
    exit;
}

$payload = json_decode($response, true);
$googleSub = $payload['sub'] ?? '';
$email = $payload['email'] ?? '';
$aud = $payload['aud'] ?? '';

// Verifikasi audience (GOOGEL_CLIENT_ID) untuk mencegah Token Forwarding Attacks
$expectedAud = getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? '');
if (!empty($expectedAud) && $aud !== $expectedAud) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token Google tidak valid untuk aplikasi ini']);
    exit;
}

if (empty($googleSub)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Google identity not found']);
    exit;
}

// Cari user berdasarkan google_id
$user = dbFetchOne("SELECT * FROM users WHERE google_id = ? AND is_active = 1", [$googleSub], "s");

if (!$user && !empty($email)) {
    // Fallback: cari berdasarkan email jika google_id belum terikat tetapi email sama
    $user = dbFetchOne("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email], "s");
    if ($user) {
        // Tautkan google_id secara otomatis
        dbQuery("UPDATE users SET google_id = ?, google_email = ?, google_linked_at = NOW() WHERE id = ?",
            [$googleSub, $email, $user['id']], "ssi");
    }
}

if (!$user) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Akun Google Anda belum terdaftar/ditautkan di sistem BEM']);
    exit;
}

// Set session login untuk user
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['nama_lengkap'] = $user['nama_lengkap'];
$_SESSION['role'] = $user['role'];
$_SESSION['email'] = $user['email'];

recordUserSession($user['id']);
auditLog('LOGIN_GOOGLE_NATIVE', 'users', $user['id'], 'Login Google Native dari aplikasi Flutter Mobile');

echo json_encode([
    'status' => 'success',
    'message' => 'Login berhasil',
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'nama_lengkap' => $user['nama_lengkap'],
        'role' => $user['role']
    ],
    'redirect_url' => baseUrl('/admin/core/dashboard.php')
]);
