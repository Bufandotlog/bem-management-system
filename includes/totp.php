<?php
// includes/totp.php - Pure PHP TOTP implementation (RFC 6238)
// VERSI: 2.0
//   CHANGED: Secret diperkuat menjadi 20 byte (160 bit)
//   ADDED: Parameter $lastUsedCounter pada totpVerify() untuk mencegah replay attack
//   ADDED: Dukungan padding pada Base32 decoding

/**
 * Generate secret key acak (Base32)
 * Disimpan di DB saat user setup 2FA pertama kali.
 * Panjang 20 byte (160 bit) sesuai rekomendasi RFC 6238.
 *
 * @return string 32 karakter Base32 (panjang sebenarnya bisa 32-40 karakter)
 */
function totpGenerateSecret(): string {
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret      = '';
    $randomBytes = random_bytes(20); // 160 bit entropy

    // Encode ke Base32 (tanpa padding)
    $n = 0;
    $bitLen = 0;
    $val = 0;
    for ($i = 0; $i < strlen($randomBytes); $i++) {
        $val    = ($val << 8) | ord($randomBytes[$i]);
        $bitLen += 8;
        while ($bitLen >= 5) {
            $bitLen -= 5;
            $secret .= $base32Chars[($val >> $bitLen) & 31];
        }
    }
    if ($bitLen > 0) {
        $secret .= $base32Chars[($val << (5 - $bitLen)) & 31];
    }

    // Base32 panjangnya sekitar 32 karakter (20 bytes = 32 base32 chars)
    return $secret;
}

/**
 * Decode Base32 ke binary (termasuk menangani padding =)
 * Dipakai secara internal oleh totpGetCode()
 *
 * @param  string $base32
 * @return string binary
 */
function totpBase32Decode(string $base32): string {
    $base32  = strtoupper($base32);
    // Hapus padding jika ada
    $base32  = rtrim($base32, '=');
    $chars   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output  = '';
    $buffer  = 0;
    $bitsLeft = 0;

    for ($i = 0; $i < strlen($base32); $i++) {
        $pos = strpos($chars, $base32[$i]);
        if ($pos === false) continue; // Lewati karakter tidak valid

        $buffer    = ($buffer << 5) | $pos;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output   .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $output;
}

/**
 * Generate kode TOTP 6 digit untuk timestamp tertentu
 *
 * @param  string $secret    Secret key dalam Base32
 * @param  int    $timestamp Unix timestamp (default: sekarang)
 * @param  int    $period    Periode dalam detik (default: 30)
 * @return string Kode 6 digit (dengan leading zero jika perlu)
 */
function totpGetCode(string $secret, int $timestamp = 0, int $period = 30): string {
    if ($timestamp === 0) {
        $timestamp = time();
    }

    // Hitung time counter (T)
    $timeCounter = (int) floor($timestamp / $period);

    // Pack sebagai 64-bit big-endian integer
    $timeBytes = pack('N*', 0) . pack('N*', $timeCounter);

    // HMAC-SHA1
    $key  = totpBase32Decode($secret);
    $hash = hash_hmac('sha1', $timeBytes, $key, true);

    // Dynamic truncation
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code   = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
        ( ord($hash[$offset + 3]) & 0xFF)
    );

    // Ambil 6 digit terakhir
    return str_pad($code % 1000000, 6, '0', STR_PAD_LEFT);
}

/**
 * Verifikasi kode TOTP yang disubmit user
 * Mengizinkan toleransi ±1 periode (30 detik before/after) untuk mengakomodasi perbedaan waktu.
 * Jika $lastUsedCounter diberikan, kode hanya dianggap valid jika time counter-nya
 * lebih besar dari counter terakhir yang digunakan (mencegah replay attack dalam periode yang sama).
 *
 * @param  string      $secret            Secret key dalam Base32
 * @param  string      $code              Kode 6 digit dari user
 * @param  int         $window            Jumlah periode toleransi (default: 1 = ±30 detik)
 * @param  int|null    $lastUsedCounter   Time counter terakhir yang berhasil digunakan (opsional)
 * @return int|false   Jika valid, kembalikan time counter yang digunakan (int); jika tidak valid, false
 */
function totpVerify(string $secret, string $code, int $window = 1, ?int $lastUsedCounter = null): int|false {
    // Sanitasi input — hanya angka, tepat 6 digit
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }

    $timestamp = time();
    $period    = 30;
    $currentCounter = (int) floor($timestamp / $period);

    // Cek kode untuk window periode (sebelum, sekarang, sesudah)
    for ($i = -$window; $i <= $window; $i++) {
        $counter = $currentCounter + $i;
        $validCode = totpGetCode($secret, $counter * $period, $period);
        // hash_equals mencegah timing attack
        if (hash_equals($validCode, $code)) {
            // Jika ada batasan replay, pastikan counter lebih besar dari last used
            if ($lastUsedCounter !== null && $counter <= $lastUsedCounter) {
                continue; // Kode ini sudah pernah digunakan, cek window lain
            }
            return $counter; // Kembalikan counter yang digunakan
        }
    }

    return false;
}

/**
 * Generate URL untuk QR Code (menggunakan API qrserver.com)
 * User scan QR ini dengan Google Authenticator / Authy
 *
 * @param  string $secret   Secret key Base32
 * @param  string $username Username admin
 * @param  string $issuer   Nama aplikasi (tampil di authenticator app)
 * @return string URL QR code image
 */
function totpGetQrUrl(string $secret, string $username, string $issuer = 'BEM Admin'): string {
    $issuer   = rawurlencode($issuer);
    $username = rawurlencode($username);
    $secret   = strtoupper($secret);

    // otpauth URI — format standar RFC
    $otpauth = "otpauth://totp/{$issuer}:{$username}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

    // QR code via qrserver.com (tidak kirim secret ke Google — hanya URL-encoded string)
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauth);
}

/**
 * Generate otpauth URI langsung (untuk library QR lain atau manual entry)
 *
 * @param  string $secret
 * @param  string $username
 * @param  string $issuer
 * @return string otpauth URI
 */
function totpGetUri(string $secret, string $username, string $issuer = 'BEM Admin'): string {
    $issuer   = rawurlencode($issuer);
    $username = rawurlencode($username);
    $secret   = strtoupper($secret);
    return "otpauth://totp/{$issuer}:{$username}?secret={$secret}&issuer={$issuer}&digits=6&period=30";
}