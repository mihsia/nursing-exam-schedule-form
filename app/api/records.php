<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Cache-Control: no-store');
app_start_session();

function require_login(): int
{
    if (empty($_SESSION['user_id'])) {
        json_response(['ok' => false, 'message' => '請先登入。'], 401);
    }
    return (int)$_SESSION['user_id'];
}

function ensure_records_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_records (
            id VARCHAR(80) NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL,
            name VARCHAR(160) NOT NULL,
            data LONGTEXT NOT NULL,
            saved_at DATETIME NOT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            INDEX idx_app_records_user_type (user_id, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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
    $userId = require_login();
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
    json_response(['ok' => false, 'message' => '伺服器錯誤：' . $error->getMessage()], 500);
}
