<?php
// includes/google-oauth.php
// Helper untuk integrasi Google OAuth 2.0 (Linking & Login)

require_once __DIR__ . '/functions.php';

/**
 * Mendapatkan konfigurasi Google OAuth 2.0 dari environment
 */
function getGoogleAuthConfig(): array {
    $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
    $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';
    $redirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: (rtrim(BASE_URL, '/') . '/admin/auth/google-callback.php');

    return [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'configured'    => !empty($clientId) && !empty($clientSecret)
    ];
}

/**
 * Membuat URL otorisasi Google OAuth 2.0
 *
 * @param string $action 'link' atau 'login'
 * @param string $csrfToken CSRF state token
 * @return string URL Google Auth
 */
function buildGoogleAuthUrl(string $action, string $csrfToken): string {
    $config = getGoogleAuthConfig();
    if (!$config['configured']) {
        return '';
    }

    $state = $action . '_' . $csrfToken;
    $_SESSION['google_auth_state'] = $state;
    $_SESSION['google_auth_action'] = $action;

    $params = [
        'client_id'     => $config['client_id'],
        'redirect_uri'  => $config['redirect_uri'],
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
        'state'         => $state
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Tukar authorization code dengan access token Google
 */
function exchangeGoogleCodeForToken(string $code): ?array {
    $config = getGoogleAuthConfig();
    if (!$config['configured'] || empty($code)) {
        return null;
    }

    $postFields = [
        'code'          => $code,
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri'  => $config['redirect_uri'],
        'grant_type'    => 'authorization_code'
    ];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("exchangeGoogleCodeForToken Error HTTP {$httpCode}: {$response}");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Ambil data user info dari Google menggunakan access token
 */
function getGoogleUserInfo(string $accessToken): ?array {
    if (empty($accessToken)) {
        return null;
    }

    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("getGoogleUserInfo Error HTTP {$httpCode}: {$response}");
        return null;
    }

    return json_decode($response, true);
}
