<?php
/**
 * Storage abstraction: Backblaze B2 e Google Drive
 * Usa apenas cURL nativo (sem SDK, sem Composer)
 */

function storageUpload(int $userId, string $tmpPath, string $originalName, string $mimeType): array {
    $user = _storageUser($userId);
    $driver = $user['storage_driver'] ?? 'b2';
    $safeFilename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    if ($driver === 'gdrive') {
        $fileId = _driveUpload($user, $tmpPath, $safeFilename, $mimeType);
        return ['driver' => 'gdrive', 'file_key' => $fileId, 'filename' => $originalName];
    }
    // default: b2
    [$fileId, $fileName] = _b2Upload($user, $tmpPath, $safeFilename, $mimeType);
    return ['driver' => 'b2', 'file_key' => $fileId . '|' . $fileName, 'filename' => $originalName];
}

function storageDelete(string $driver, string $fileKey, int $ownerUserId): void {
    $user = _storageUser($ownerUserId);
    if ($driver === 'gdrive') {
        _driveDelete($user, $fileKey);
    } else {
        [$fileId, $fileName] = explode('|', $fileKey, 2);
        _b2Delete($user, $fileId, $fileName);
    }
}

function storageStream(string $driver, string $fileKey, int $ownerUserId, string $filename, string $mimeType): void {
    $user = _storageUser($ownerUserId);
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    if ($driver === 'gdrive') {
        _driveStream($user, $fileKey);
    } else {
        [$fileId, $fileName] = explode('|', $fileKey, 2);
        _b2Stream($user, $fileName);
    }
}

/* ── Helpers ───────────────────────────────────────────────── */

function _storageUser(int $userId): array {
    $db = getDB();
    $st = $db->prepare("SELECT storage_driver, b2_key_id, b2_app_key, b2_bucket_id, b2_bucket_name,
        gdrive_service_account_json, gdrive_folder_id FROM usuarios WHERE id = ?");
    $st->execute([$userId]);
    return $st->fetch() ?: [];
}

/* ── Backblaze B2 ──────────────────────────────────────────── */

function _b2Auth(array $user): array {
    $creds = base64_encode($user['b2_key_id'] . ':' . $user['b2_app_key']);
    $ch = curl_init('https://api.backblazeb2.com/b2api/v3/b2_authorize_account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Basic $creds"],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['authorizationToken'])) throw new RuntimeException('B2 auth failed: ' . json_encode($res));
    return $res;
}

function _b2Upload(array $user, string $tmpPath, string $fileName, string $mimeType): array {
    $auth = _b2Auth($user);
    // Get upload URL
    $ch = curl_init($auth['apiUrl'] . '/b2api/v3/b2_get_upload_url');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['bucketId' => $user['b2_bucket_id']]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth['authorizationToken'],
            'Content-Type: application/json',
        ],
    ]);
    $upRes = json_decode(curl_exec($ch), true);
    curl_close($ch);
    // Upload file
    $content = file_get_contents($tmpPath);
    $sha1 = sha1($content);
    $ch = curl_init($upRes['uploadUrl']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $content,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $upRes['authorizationToken'],
            'X-Bz-File-Name: ' . rawurlencode($fileName),
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($content),
            'X-Bz-Content-Sha1: ' . $sha1,
        ],
    ]);
    $fileRes = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($fileRes['fileId'])) throw new RuntimeException('B2 upload failed: ' . json_encode($fileRes));
    return [$fileRes['fileId'], $fileRes['fileName']];
}

function _b2Delete(array $user, string $fileId, string $fileName): void {
    $auth = _b2Auth($user);
    $ch = curl_init($auth['apiUrl'] . '/b2api/v3/b2_delete_file_version');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['fileId' => $fileId, 'fileName' => $fileName]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth['authorizationToken'],
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function _b2Stream(array $user, string $fileName): void {
    $auth = _b2Auth($user);
    $url = rtrim($auth['downloadUrl'], '/') . '/file/' . $user['b2_bucket_name'] . '/' . rawurlencode($fileName);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $auth['authorizationToken']],
    ]);
    echo curl_exec($ch);
    curl_close($ch);
}

/* ── Google Drive ──────────────────────────────────────────── */

function _base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _driveToken(array $user): string {
    $sa = json_decode($user['gdrive_service_account_json'], true);
    $now = time();
    $header  = _base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = _base64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $sigInput = "$header.$payload";
    openssl_sign($sigInput, $sig, $sa['private_key'], 'sha256WithRSAEncryption');
    $jwt = $sigInput . '.' . _base64url($sig);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) throw new RuntimeException('Drive token failed: ' . json_encode($res));
    return $res['access_token'];
}

function _driveUpload(array $user, string $tmpPath, string $fileName, string $mimeType): string {
    $token    = _driveToken($user);
    $content  = file_get_contents($tmpPath);
    $meta     = json_encode(['name' => $fileName, 'parents' => [$user['gdrive_folder_id']]]);
    $boundary = 'gdrive_bnd_' . uniqid();
    $body     = "--$boundary\r\nContent-Type: application/json\r\n\r\n$meta\r\n"
              . "--$boundary\r\nContent-Type: $mimeType\r\n\r\n$content\r\n--$boundary--";
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: multipart/related; boundary=$boundary",
        ],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['id'])) throw new RuntimeException('Drive upload failed: ' . json_encode($res));
    return $res['id'];
}

function _driveDelete(array $user, string $fileId): void {
    $token = _driveToken($user);
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function _driveStream(array $user, string $fileId): void {
    $token = _driveToken($user);
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId?alt=media");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    echo curl_exec($ch);
    curl_close($ch);
}
