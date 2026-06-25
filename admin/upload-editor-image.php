<?php
// admin/upload-editor-image.php
// Endpoint untuk upload gambar dari text editor Quill

require_once __DIR__ . '/config.php';

// Atur header response agar selalu JSON
header('Content-Type: application/json');

// Cek apakah user sudah login
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Silakan login terlebih dahulu.'
    ]);
    exit();
}

// Cek CSRF token untuk keamanan upload
if (!csrfVerify()) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid CSRF token.'
    ]);
    exit();
}

// Cek file upload
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'success' => false,
        'message' => 'Tidak ada file yang diunggah atau terjadi kesalahan upload.'
    ]);
    exit();
}

// Upload file menggunakan helper uploadFile bawaan
// Folder upload: 'berita'
$uploadResult = uploadFile($_FILES['image'], 'berita');

if ($uploadResult) {
    // Dapatkan URL absolut atau relatif untuk disematkan ke editor
    $url = uploadUrl($uploadResult);
    echo json_encode([
        'success' => true,
        'url' => $url
    ]);
} else {
    // Ambil pesan error dari session (karena uploadFile mengisi $_SESSION['error'])
    $errorMsg = $_SESSION['error'] ?? 'Gagal mengunggah file gambar.';
    unset($_SESSION['error']);
    echo json_encode([
        'success' => false,
        'message' => $errorMsg
    ]);
}
exit();
