<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Cache-Control: no-store');
app_start_session();

function base64url_decode_json(string $value): ?array
{
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    if ($decoded === false) {
        return null;
    }
    $data = json_decode($decoded, true);
    return is_array($data) ? $data : null;
}

function authorization_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$header), $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function firebase_certs(): array
{
    $cacheFile = sys_get_temp_dir() . '/nursing_exam_firebase_certs.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 3600) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $json = @file_get_contents('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
    if ($json === false) {
        return [];
    }
    $certs = json_decode($json, true);
    if (!is_array($certs)) {
        return [];
    }
    @file_put_contents($cacheFile, json_encode($certs));
    return $certs;
}

function verify_firebase_token(string $token, string $projectId): ?array
{
    if ($projectId === '' || !function_exists('openssl_verify')) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = base64url_decode_json($encodedHeader);
    $payload = base64url_decode_json($encodedPayload);
    $signature = base64_decode(strtr($encodedSignature, '-_', '+/') . str_repeat('=', (4 - strlen($encodedSignature) % 4) % 4), true);
    if (!$header || !$payload || $signature === false || ($header['alg'] ?? '') !== 'RS256') {
        return null;
    }

    $cert = firebase_certs()[(string)($header['kid'] ?? '')] ?? null;
    if (!$cert || openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $cert, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }

    $now = time();
    $issuer = 'https://securetoken.google.com/' . $projectId;
    if (($payload['aud'] ?? '') !== $projectId || ($payload['iss'] ?? '') !== $issuer) {
        return null;
    }
    if (empty($payload['sub']) || strlen((string)$payload['sub']) > 128) {
        return null;
    }
    if ((int)($payload['exp'] ?? 0) <= $now || (int)($payload['iat'] ?? 0) > $now + 300) {
        return null;
    }

    return $payload;
}

function firebase_user_id(PDO $pdo, array $claims): int
{
    $username = 'firebase:' . hash('sha256', (string)$claims['sub']);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO users (username, password_hash, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$username, 'firebase-auth-managed']);

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('Unable to create Firebase user mapping.');
    }
    return (int)$id;
}

function require_login(PDO $pdo): int
{
    if (empty($_SESSION['user_id'])) {
        $config = app_config();
        $token = authorization_bearer_token();
        $claims = $token ? verify_firebase_token($token, (string)($config['firebase_project_id'] ?? '')) : null;
        if (!$claims) {
            json_response(['ok' => false, 'message' => '請先登入。'], 401);
        }
        return firebase_user_id($pdo, $claims);
    }
    return (int)$_SESSION['user_id'];
}

function ensure_records_table(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM app_records LIMIT 1');
        return;
    } catch (PDOException $error) {
        $code = (int)($error->errorInfo[1] ?? 0);
        if ($code !== 1146) {
            throw $error;
        }
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_records (
                id VARCHAR(80) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(40) NOT NULL,
                name VARCHAR(160) NOT NULL,
                data LONGTEXT NOT NULL,
                saved_at DATETIME NOT NULL,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (user_id, id),
                INDEX idx_app_records_user_type (user_id, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $error) {
        $code = (int)($error->errorInfo[1] ?? 0);
        if ($code === 1142) {
            json_response([
                'ok' => false,
                'message' => '資料表 app_records 尚未建立，且目前資料庫帳號沒有 CREATE 權限。請先用 phpMyAdmin 管理員帳號執行 api/fix_app_records.sql，再回到系統重新整理。',
            ], 500);
        }
        throw $error;
    }
}

function clean_type(string $type): string
{
    $type = trim($type);
    if (!preg_match('/^[a-z0-9_-]{1,40}$/i', $type)) {
        json_response(['ok' => false, 'message' => '紀錄類型錯誤。'], 422);
    }
    return $type;
}

function draft_id(int $userId, string $type): string
{
    return sprintf('%s-draft-%d', $type, $userId);
}

function normalize_record(array $record): array
{
    $data = json_decode((string)$record['data'], true);
    return [
        'id' => (string)$record['id'],
        'type' => (string)$record['type'],
        'name' => (string)$record['name'],
        'savedAt' => (string)$record['saved_at'],
        'savedAtText' => date('Y/m/d H:i:s', strtotime((string)$record['saved_at'])),
        'data' => is_array($data) ? $data : [],
    ];
}

try {
    $pdo = app_pdo();
    ensure_records_table($pdo);
    $userId = require_login($pdo);
    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';

    if ($action === 'list') {
        $type = clean_type((string)($_GET['type'] ?? ''));
        $stmt = $pdo->prepare(
            'SELECT id, type, name, data, saved_at
             FROM app_records
             WHERE user_id = ? AND type = ? AND id <> ?
             ORDER BY saved_at DESC, updated_at DESC'
        );
        $stmt->execute([$userId, $type, draft_id($userId, $type)]);
        json_response([
            'ok' => true,
            'records' => array_map('normalize_record', $stmt->fetchAll()),
        ]);
    }

    if ($action === 'draft') {
        $type = clean_type((string)($_GET['type'] ?? ''));
        $stmt = $pdo->prepare('SELECT data, saved_at FROM app_records WHERE user_id = ? AND id = ? LIMIT 1');
        $stmt->execute([$userId, draft_id($userId, $type)]);
        $draft = $stmt->fetch();
        $data = $draft ? json_decode((string)$draft['data'], true) : null;
        json_response([
            'ok' => true,
            'draft' => [
                'savedAt' => $draft['saved_at'] ?? null,
                'data' => is_array($data) ? $data : null,
            ],
        ]);
    }

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        json_response(['ok' => false, 'message' => '資料格式錯誤。'], 400);
    }

    if ($action === 'save') {
        $record = is_array($input['record'] ?? null) ? $input['record'] : [];
        $type = clean_type((string)($record['type'] ?? ''));
        $id = trim((string)($record['id'] ?? ''));
        $name = trim((string)($record['name'] ?? ''));
        $data = $record['data'] ?? null;
        if ($id === '' || $name === '' || !is_array($data)) {
            json_response(['ok' => false, 'message' => '紀錄資料不完整。'], 422);
        }
        $ownerStmt = $pdo->prepare('SELECT user_id FROM app_records WHERE id = ? LIMIT 1');
        $ownerStmt->execute([$id]);
        $ownerId = $ownerStmt->fetchColumn();
        if ($ownerId !== false && (int)$ownerId !== $userId) {
            json_response(['ok' => false, 'message' => '無權更新此紀錄。'], 403);
        }
        $savedAt = (string)($record['savedAt'] ?? date('Y-m-d H:i:s'));
        $savedAtSql = date('Y-m-d H:i:s', strtotime($savedAt) ?: time());
        $stmt = $pdo->prepare(
            'INSERT INTO app_records (id, user_id, type, name, data, saved_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               name = VALUES(name),
               data = VALUES(data),
               saved_at = VALUES(saved_at),
               updated_at = NOW()'
        );
        $stmt->execute([
            $id,
            $userId,
            $type,
            $name,
            json_encode($data, JSON_UNESCAPED_UNICODE),
            $savedAtSql,
        ]);
        json_response(['ok' => true, 'record' => $record]);
    }

    if ($action === 'save_draft') {
        $type = clean_type((string)($input['type'] ?? ''));
        $data = $input['data'] ?? null;
        if (!is_array($data)) {
            json_response(['ok' => false, 'message' => '草稿資料不完整。'], 422);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO app_records (id, user_id, type, name, data, saved_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE data = VALUES(data), saved_at = NOW(), updated_at = NOW()'
        );
        $stmt->execute([
            draft_id($userId, $type),
            $userId,
            $type,
            '__draft__',
            json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
        json_response(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = trim((string)($input['id'] ?? ''));
        if ($id === '') {
            json_response(['ok' => false, 'message' => '缺少紀錄 ID。'], 422);
        }
        $stmt = $pdo->prepare('DELETE FROM app_records WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'message' => '未知的操作。'], 404);
} catch (Throwable $error) {
    error_log((string)$error);
    json_response(['ok' => false, 'message' => '伺服器暫時無法處理，請稍後再試。'], 500);
}
