<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Cache-Control: no-store');
app_start_session();

try {
    $pdo = app_pdo();
    $action = $_GET['action'] ?? $_POST['action'] ?? 'status';

    if ($action === 'status') {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        json_response([
            'ok' => true,
            'configured' => $count > 0,
            'authenticated' => !empty($_SESSION['user_id']),
            'username' => $_SESSION['username'] ?? null,
        ]);
    }

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        json_response(['ok' => false, 'message' => '資料格式錯誤。'], 400);
    }

    if ($action === 'setup') {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            json_response(['ok' => false, 'message' => '管理帳號已建立，請直接登入。'], 409);
        }

        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($username === '' || $password === '') {
            json_response(['ok' => false, 'message' => '請輸入帳號與密碼。'], 422);
        }
        if (mb_strlen($password) < 8) {
            json_response(['ok' => false, 'message' => '密碼至少需要 8 個字元。'], 422);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$username, $hash]);
        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
        $_SESSION['username'] = $username;
        json_response(['ok' => true, 'message' => '管理帳號已建立。']);
    }

    if ($action === 'login') {
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_response(['ok' => false, 'message' => '帳號或密碼不正確。'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        json_response(['ok' => true, 'message' => '登入成功。']);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        json_response(['ok' => true, 'message' => '已登出。']);
    }

    json_response(['ok' => false, 'message' => '未知的操作。'], 404);
} catch (Throwable $error) {
    error_log((string)$error);
    json_response(['ok' => false, 'message' => '伺服器暫時無法處理，請稍後再試。'], 500);
}
