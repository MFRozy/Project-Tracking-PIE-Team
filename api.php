<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$routeParam = $_GET['route'] ?? null;
if (is_string($routeParam) && $routeParam !== '') {
    $requestPath = $routeParam;
} else {
    $matches = [];
    if (preg_match('#(?:/ProjectTracker)?/api\.php(?:/(.*))?$#', $requestPath, $matches) === 1) {
        $subPath = $matches[1] ?? '';
        $requestPath = $subPath === '' ? '/api/health' : '/api/' . $subPath;
    } elseif (preg_match('#^/ProjectTracker(/api/.*)$#', $requestPath, $matches) === 1) {
        $requestPath = $matches[1];
    }
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function ensureLogin(): ?array
{
    return currentUser();
}

function isLocalRequest(): bool
{
    return in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}

function safeName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';
    return trim($name, '. _') ?: 'file';
}

function isValidUserName(string $name): bool
{
    $trimmed = trim($name);
    return mb_strlen($trimmed) >= 2
        && mb_strlen($trimmed) <= 60
        && preg_match('/[\x00-\x1F<>"\'\\\\\/]/', $trimmed) !== 1;
}

function isValidPin(string $pin): bool
{
    $len = strlen($pin);
    return $len >= PIN_MIN_LENGTH && $len <= 64;
}

function validateAndCleanSettings(array $incoming): array
{
    if (isset($incoming['categories']) && is_array($incoming['categories'])) {
        $cleanCats = [];
        foreach ($incoming['categories'] as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $cName = trim((string)($cat['name'] ?? ''));
            if ($cName === '' || mb_strlen($cName) > 80) {
                continue;
            }
            $cColor = trim((string)($cat['color'] ?? '#2563eb'));
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $cColor) !== 1) {
                $cColor = '#2563eb';
            }
            $cleanCats[] = ['name' => $cName, 'color' => $cColor];
        }
        if (!empty($cleanCats)) {
            $incoming['categories'] = $cleanCats;
        }
    }
    if (isset($incoming['appName'])) {
        $incoming['appName'] = substr(trim((string)$incoming['appName']) ?: APP_NAME, 0, 100);
    }
    return $incoming;
}

function readJsonSetting(string $key): ?array
{
    $stmt = db()->prepare('SELECT value_json FROM settings WHERE key_name = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if (!is_string($value)) {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function readJsonSettingForUpdate(PDO $pdo, string $key): ?array
{
    $pdo->prepare('INSERT IGNORE INTO settings (key_name, value_json) VALUES (:key, NULL)')->execute([':key' => $key]);
    $stmt = $pdo->prepare('SELECT value_json FROM settings WHERE key_name = :key LIMIT 1 FOR UPDATE');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if (!is_string($value)) {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function writeJsonSetting(string $key, array $value, ?PDO $pdo = null): void
{
    $conn = $pdo ?? db();
    $conn->prepare('INSERT INTO settings (key_name, value_json) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)')->execute([
        ':key' => $key,
        ':value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
}

function appSettings(?PDO $pdo = null, bool $forUpdate = false): array
{
    $conn = $pdo ?? db();
    $settings = $forUpdate ? readJsonSettingForUpdate($conn, 'tracker') : readJsonSetting('tracker');
    if ($settings !== null) {
        if (in_array((string)($settings['appName'] ?? ''), ['Project Tracker', 'PI Project Tracker'], true)) {
            $settings['appName'] = APP_NAME;
            writeJsonSetting('tracker', $settings, $conn);
        }
        return $settings;
    }

    $app = readJsonSetting('app') ?? [];
    $categories = readJsonSetting('categories') ?? [];
    if (isset($categories['items']) && is_array($categories['items'])) {
        $app['categories'] = $categories['items'];
    }
    if (in_array((string)($app['appName'] ?? ''), ['', 'Project Tracker', 'PI Project Tracker'], true)) {
        $app['appName'] = APP_NAME;
    }
    return $app;
}

function syncState(?PDO $pdo = null, bool $forUpdate = false): array
{
    $conn = $pdo ?? db();
    $state = $forUpdate ? readJsonSettingForUpdate($conn, 'tracker_state') : readJsonSetting('tracker_state');
    return $state ?? ['rev' => 0, 'deleted' => [], 'improvements' => []];
}

function projectRows(?array $forUser = null): array
{
    $rows = db()->query('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll();
    $items = array_map('projectToOutput', $rows);
    if ($forUser !== null && ($forUser['role'] ?? '') === 'contributor') {
        $uName = strtolower(trim((string)($forUser['name'] ?? '')));
        return array_values(array_filter(
            $items,
            static fn(array $p): bool => strtolower(trim((string)($p['pic'] ?? ''))) === $uName
                || strtolower(trim((string)($p['createdBy'] ?? ''))) === $uName
        ));
    }
    return $items;
}

function requireEditor(array $user): void
{
    if (($user['role'] ?? '') === 'viewer') {
        jsonResponse(403, ['error' => 'Akses Viewer hanya dapat melihat data']);
    }
}

function requireProjectEditor(array $user): void
{
    if (!in_array(($user['role'] ?? ''), ['admin', 'user'], true)) {
        jsonResponse(403, ['error' => 'Akses Kontributor/Viewer tidak dapat mengubah Project']);
    }
}

function issueNewSession(PDO $pdo, string $userId): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO sessions (id, user_id, token_hash, expires_at, created_at)
         VALUES (:id, :user_id, :token_hash, DATE_ADD(NOW(), INTERVAL :lifetime SECOND), NOW())'
    );
    $stmt->execute([
        ':id' => uuid4(),
        ':user_id' => $userId,
        ':token_hash' => hash_hmac('sha256', $token, SESSION_SECRET),
        ':lifetime' => SESSION_LIFETIME,
    ]);
    setSessionCookie($token);
    return $token;
}

if ($requestPath === '/api/health') {
    jsonResponse(200, ['ok' => true, 'app' => APP_NAME, 'version' => APP_VERSION]);
}

if ($requestPath === '/api/public' && $method === 'GET') {
    try {
        $count = (int)db()->query("SELECT COUNT(*) FROM users WHERE role <> 'viewer'")->fetchColumn();
        $users = db()->query("SELECT name FROM users WHERE role IN ('admin', 'user') ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $contributors = db()->query("SELECT name FROM users WHERE role = 'contributor' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $viewers = db()->query("SELECT name FROM users WHERE role = 'viewer' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $settings = appSettings();
        jsonResponse(200, [
            'needSetup' => $count === 0,
            'local' => isLocalRequest(),
            'users' => $users,
            'contributors' => $contributors,
            'viewers' => $viewers,
            'appName' => (string)($settings['appName'] ?? APP_NAME),
            'version' => APP_VERSION,
            'pinMinLength' => PIN_MIN_LENGTH,
        ]);
    } catch (Throwable $e) {
        jsonResponse(500, ['error' => 'Database belum siap. Pastikan layanan MySQL aktif dan konfigurasi database sudah sesuai.']);
    }
}

if ($requestPath === '/api/setup' && $method === 'POST') {
    verifyCsrfRequest(false);
    try {
        $count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        jsonResponse(500, ['error' => 'Database belum dibuat. Import schema.sql terlebih dahulu.']);
    }

    if ($count > 0) {
        jsonResponse(403, ['error' => 'Admin sudah dibuat']);
    }
    if (!isLocalRequest()) {
        jsonResponse(403, ['error' => 'Admin pertama hanya dapat dibuat dari PC server (localhost)']);
    }

    $data = jsonBody();
    $name = trim((string)($data['name'] ?? ''));
    $pin = (string)($data['pin'] ?? '');

    if (!isValidUserName($name) || !isValidPin($pin)) {
        jsonResponse(400, ['error' => 'Nama minimal 2 huruf dan PIN minimal ' . PIN_MIN_LENGTH . ' karakter']);
    }

    $pdo = db();
    $userId = uuid4();
    $pdo->prepare('INSERT INTO users (id, name, password_hash, role, created_at) VALUES (:id, :name, :password_hash, :role, NOW())')->execute([
        ':id' => $userId,
        ':name' => $name,
        ':password_hash' => password_hash($pin, PASSWORD_DEFAULT),
        ':role' => 'admin',
    ]);

    $token = issueNewSession($pdo, $userId);
    recordAuditLog($pdo, 'AUTH', $name, 'Setup admin pertama berhasil dibuat');

    $row = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $row->execute([':id' => $userId]);
    $user = $row->fetch();

    jsonResponse(200, [
        'user' => userPublicArray($user),
        'csrfToken' => csrfTokenForSession($token),
        'serverTime' => (int)round(microtime(true) * 1000),
    ]);
}

if ($requestPath === '/api/login' && $method === 'POST') {
    verifyCsrfRequest(false);
    $data = jsonBody();
    $name = trim((string)($data['name'] ?? ''));
    $pin = (string)($data['pin'] ?? '');
    $pdo = db();
    $loginKey = hash('sha256', strtolower($name) . "\0" . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO login_attempts (login_key, failed_count, locked_until) VALUES (:key, 0, NULL)')->execute([':key' => $loginKey]);
        $attemptStmt = $pdo->prepare('SELECT failed_count, locked_until FROM login_attempts WHERE login_key = :key FOR UPDATE');
        $attemptStmt->execute([':key' => $loginKey]);
        $attempt = $attemptStmt->fetch();
        $waitStmt = $pdo->prepare('SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) FROM login_attempts WHERE login_key = :key');
        $waitStmt->execute([':key' => $loginKey]);
        $wait = (int)$waitStmt->fetchColumn();
        if ($wait > 0) {
            $pdo->commit();
            jsonResponse(429, ['error' => 'Terlalu banyak percobaan PIN. Coba lagi dalam ' . (int)ceil($wait / 60) . ' menit.']);
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pin, $user['password_hash'])) {
            $failedCount = (int)($attempt['failed_count'] ?? 0) + 1;
            if ($failedCount >= 5) {
                $pdo->prepare('UPDATE login_attempts SET failed_count = 0, locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE login_key = :key')->execute([':key' => $loginKey]);
                recordAuditLog($pdo, 'AUTH', $name ?: 'unknown', 'Akun dikunci sementara karena 5x gagal login dari ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                $pdo->commit();
                jsonResponse(429, ['error' => 'Terlalu banyak percobaan PIN. Akun ini dikunci selama 5 menit.']);
            }
            $pdo->prepare('UPDATE login_attempts SET failed_count = :count, locked_until = NULL WHERE login_key = :key')->execute([':count' => $failedCount, ':key' => $loginKey]);
            $pdo->commit();
            jsonResponse(401, ['error' => 'Nama atau PIN salah']);
        }

        $pdo->prepare('DELETE FROM login_attempts WHERE login_key = :key')->execute([':key' => $loginKey]);
        $pdo->exec('DELETE FROM sessions WHERE expires_at <= NOW()');
        $token = issueNewSession($pdo, (string)$user['id']);
        recordAuditLog($pdo, 'AUTH', (string)$user['name'], 'Login berhasil dari ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    jsonResponse(200, [
        'user' => userPublicArray($user),
        'csrfToken' => csrfTokenForSession($token),
        'serverTime' => (int)round(microtime(true) * 1000),
    ]);
}

$user = ensureLogin();
if (!$user && !in_array($requestPath, ['/api/health', '/api/public', '/api/setup', '/api/login'], true)) {
    jsonResponse(401, ['error' => 'Belum masuk']);
}

if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    verifyCsrfRequest(true);
}

if ($requestPath === '/api/me' && $method === 'GET') {
    jsonResponse(200, [
        'user' => userPublicArray($user),
        'csrfToken' => currentCsrfToken(),
        'serverTime' => (int)round(microtime(true) * 1000),
        'version' => APP_VERSION,
        'pinMinLength' => PIN_MIN_LENGTH,
    ]);
}

if ($requestPath === '/api/logout' && $method === 'POST') {
    $token = currentSessionToken();
    if ($token !== null) {
        $hash = hash_hmac('sha256', $token, SESSION_SECRET);
        db()->prepare('DELETE FROM sessions WHERE token_hash = :hash')->execute([':hash' => $hash]);
    }
    recordAuditLog(db(), 'AUTH', (string)$user['name'], 'Logout dari aplikasi');
    clearSessionCookie();
    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/users' && $method === 'GET') {
    requireAdmin($user);
    $rows = db()->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $rows)]);
}

if ($requestPath === '/api/users' && $method === 'POST') {
    requireAdmin($user);
    $data = jsonBody();
    $name = trim((string)($data['name'] ?? ''));
    $pin = (string)($data['pin'] ?? '');
    $role = in_array((string)($data['role'] ?? 'user'), ['admin', 'user', 'viewer', 'contributor'], true) ? (string)($data['role'] ?? 'user') : 'user';

    if (!isValidUserName($name) || !isValidPin($pin)) {
        jsonResponse(400, ['error' => 'Nama minimal 2 huruf dan PIN minimal ' . PIN_MIN_LENGTH . ' karakter']);
    }

    $pdo = db();
    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $exists->execute([':name' => $name]);
    if ((int)$exists->fetchColumn() > 0) {
        jsonResponse(400, ['error' => 'Nama sudah dipakai']);
    }

    $id = uuid4();
    $pdo->prepare('INSERT INTO users (id, name, password_hash, role, created_at) VALUES (:id, :name, :password_hash, :role, NOW())')->execute([
        ':id' => $id,
        ':name' => $name,
        ':password_hash' => password_hash($pin, PASSWORD_DEFAULT),
        ':role' => $role,
    ]);
    recordAuditLog($pdo, 'AUTH', (string)$user['name'], "Menambah pengguna baru: {$name} (role: {$role})");

    $users = $pdo->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $users)]);
}

if (preg_match('#^/api/users/([^/]+)$#', $requestPath, $matches) && $method === 'PUT') {
    requireAdmin($user);
    $targetName = urldecode($matches[1]);
    $data = jsonBody();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $targetName]);
    $target = $stmt->fetch();
    if (!$target) {
        jsonResponse(404, ['error' => 'Pengguna tidak ditemukan']);
    }

    if (array_key_exists('pin', $data)) {
        $newPin = (string)$data['pin'];
        if (!isValidPin($newPin)) {
            jsonResponse(400, ['error' => 'PIN baru minimal ' . PIN_MIN_LENGTH . ' karakter']);
        }
        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
            ':hash' => password_hash($newPin, PASSWORD_DEFAULT),
            ':id' => $target['id'],
        ]);
        // Cabut seluruh sesi lama milik pengguna yang di-reset PIN-nya
        $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $target['id']]);
        recordAuditLog($pdo, 'AUTH', (string)$user['name'], "Reset PIN dan cabut sesi untuk pengguna: {$target['name']}");
    }

    if (array_key_exists('role', $data)) {
        $newRole = in_array((string)$data['role'], ['admin', 'user', 'viewer', 'contributor'], true) ? (string)$data['role'] : 'user';
        if ($target['id'] === $user['id'] && $newRole !== 'admin') {
            jsonResponse(400, ['error' => 'Tidak bisa menurunkan peran diri sendiri']);
        }
        if ($target['role'] === 'admin' && $newRole !== 'admin') {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                jsonResponse(400, ['error' => 'Harus ada minimal satu admin']);
            }
        }
        if ($newRole !== $target['role']) {
            $pdo->prepare('UPDATE users SET role = :role WHERE id = :id')->execute([':role' => $newRole, ':id' => $target['id']]);
            $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $target['id']]);
            recordAuditLog($pdo, 'AUTH', (string)$user['name'], "Mengubah role {$target['name']} dari {$target['role']} menjadi {$newRole}");
        }
    }

    $users = $pdo->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $users)]);
}

if (preg_match('#^/api/users/([^/]+)$#', $requestPath, $matches) && $method === 'DELETE') {
    requireAdmin($user);
    $targetName = urldecode($matches[1]);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $targetName]);
    $target = $stmt->fetch();
    if (!$target) {
        jsonResponse(404, ['error' => 'Pengguna tidak ditemukan']);
    }
    if ($target['id'] === $user['id']) {
        jsonResponse(400, ['error' => 'Tidak bisa menghapus akun sendiri']);
    }
    if ($target['role'] === 'admin') {
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount <= 1) {
            jsonResponse(400, ['error' => 'Harus ada minimal satu admin']);
        }
    }

    $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $target['id']]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $target['id']]);
    recordAuditLog($pdo, 'AUTH', (string)$user['name'], "Menghapus pengguna: {$target['name']} (role: {$target['role']})");
    $users = $pdo->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $users)]);
}

if ($requestPath === '/api/sync' && $method === 'POST') {
    $payload = jsonBody();
    $push = is_array($payload['push'] ?? null) ? $payload['push'] : [];
    if ($user['role'] === 'viewer' && (!empty($push['projects']) || !empty($push['improvements']) || !empty($push['deleted']) || isset($push['settings']))) {
        jsonResponse(403, ['error' => 'Akses Viewer hanya dapat melihat data']);
    }
    if ($user['role'] === 'contributor' && (!empty($push['projects']) || !empty($push['deleted']) || isset($push['settings']))) {
        jsonResponse(403, ['error' => 'Akses Kontributor hanya dapat mengubah Improvement']);
    }

    $pdo = db();
    $pdo->beginTransaction();

    $changed = false;
    $rejectedSettings = isset($push['settings']) && $user['role'] !== 'admin';
    $rejectedProjectAuth = false;
    $serverNow = (int)round(microtime(true) * 1000);

    try {
        // Baca state dan settings di dalam transaksi dengan row lock (FOR UPDATE)
        $state = syncState($pdo, true);
        $storedSettings = readJsonSettingForUpdate($pdo, 'tracker');
        $settings = $storedSettings ?? appSettings($pdo, false);
        $deleted = is_array($state['deleted'] ?? null) ? $state['deleted'] : [];
        $improvements = [];
        foreach (is_array($state['improvements'] ?? null) ? $state['improvements'] : [] as $item) {
            if (is_array($item) && isset($item['id']) && isValidEntityId((string)$item['id'])) {
                $improvements[(string)$item['id']] = $item;
            }
        }

        foreach (is_array($push['deleted'] ?? null) ? $push['deleted'] : [] as $projectId => $timestamp) {
            $projectIdStr = (string)$projectId;
            if (!isValidEntityId($projectIdStr)) {
                continue;
            }
            $clientTs = (int)$timestamp;
            if ($clientTs <= 0 || $clientTs <= (int)($deleted[$projectIdStr] ?? 0)) {
                continue;
            }
            $exists = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1 FOR UPDATE');
            $exists->execute([':id' => $projectIdStr]);
            $row = $exists->fetch();
            if ($row) {
                if (!canDeleteProject($user, $row)) {
                    $rejectedProjectAuth = true;
                    continue;
                }
                $rowUpdatedAt = (int)projectToOutput($row)['updatedAt'];
                if ($clientTs >= $rowUpdatedAt) {
                    $pdo->prepare('DELETE FROM project_stages WHERE project_id = :id')->execute([':id' => $projectIdStr]);
                    $pdo->prepare('DELETE FROM project_milestones WHERE project_id = :id')->execute([':id' => $projectIdStr]);
                    $pdo->prepare('DELETE FROM project_quotations WHERE project_id = :id')->execute([':id' => $projectIdStr]);
                    $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => $projectIdStr]);
                    $deleted[$projectIdStr] = max($serverNow, $clientTs);
                    recordAuditLog($pdo, $projectIdStr, (string)$user['name'], "Menghapus project {$row['code']} - {$row['name']}");
                    $changed = true;
                }
            } else {
                $deleted[$projectIdStr] = max($serverNow, $clientTs);
                $changed = true;
            }
        }

        foreach (is_array($push['projects'] ?? null) ? $push['projects'] : [] as $item) {
            if (!is_array($item) || empty($item['id']) || !isValidEntityId((string)$item['id'])) {
                continue;
            }
            $pid = (string)$item['id'];
            $clientUpdatedAt = (int)($item['updatedAt'] ?? 0);
            if ($clientUpdatedAt <= (int)($deleted[$pid] ?? 0)) {
                continue;
            }

            $exists = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1 FOR UPDATE');
            $exists->execute([':id' => $pid]);
            $row = $exists->fetch() ?: null;

            if ($row !== null) {
                $existingOutput = projectToOutput($row);
                if ($clientUpdatedAt > 0 && $clientUpdatedAt < (int)$existingOutput['updatedAt']) {
                    continue;
                }
                if (!canModifyProject($user, $row)) {
                    $rejectedProjectAuth = true;
                    continue;
                }
            }

            try {
                $project = normalizeProjectPayload($item, $row, $user);
            } catch (InvalidArgumentException $ve) {
                $pdo->rollBack();
                jsonResponse(400, ['error' => $ve->getMessage()]);
            }

            $existingUpdatedAt = $row !== null ? (int)projectToOutput($row)['updatedAt'] : 0;
            $effectiveUpdatedAt = max($serverNow, $clientUpdatedAt, $existingUpdatedAt + 1);
            $serverNow = max($serverNow, $effectiveUpdatedAt);

            $project['metadata']['updatedAt'] = $effectiveUpdatedAt;
            $project['metadata']['updatedBy'] = $user['name'];
            $project['updated_by'] = $user['name'];

            $values = [
                ':id' => $project['id'],
                ':code' => $project['code'],
                ':name' => $project['name'],
                ':category' => $project['category'],
                ':status' => $project['status'],
                ':priority' => $project['priority'],
                ':pic' => $project['pic'],
                ':area' => $project['area'],
                ':start_date' => $project['start_date'] !== '' ? $project['start_date'] : null,
                ':end_date' => $project['end_date'] !== '' ? $project['end_date'] : null,
                ':progress' => $project['progress'],
                ':description' => $project['description'],
                ':vendor' => $project['vendor'],
                ':budget' => $project['budget'],
                ':actual' => $project['actual'],
                ':po' => $project['po'],
                ':metadata' => json_encode($project['metadata'], JSON_UNESCAPED_SLASHES),
                ':updated_by' => $user['name'],
            ];

            if ($row !== null) {
                $pdo->prepare(
                    'UPDATE projects SET code=:code, name=:name, category=:category, status=:status, priority=:priority,
                     pic=:pic, area=:area, start_date=:start_date, end_date=:end_date, progress=:progress,
                     description=:description, vendor=:vendor, budget=:budget, actual=:actual, po=:po,
                     metadata=:metadata, updated_at=NOW(), updated_by=:updated_by WHERE id=:id'
                )->execute($values);
                recordAuditLog(
                    $pdo,
                    $project['id'],
                    (string)$user['name'],
                    sprintf(
                        'Memperbarui project %s (%s) [status: %s -> %s, progres: %d%% -> %d%%]',
                        $project['code'],
                        $project['name'],
                        (string)$row['status'],
                        $project['status'],
                        (int)$row['progress'],
                        $project['progress']
                    )
                );
            } else {
                $pdo->prepare(
                    'INSERT INTO projects (id, code, name, category, status, priority, pic, area, start_date, end_date,
                     progress, description, vendor, budget, actual, po, metadata, updated_at, updated_by)
                     VALUES (:id,:code,:name,:category,:status,:priority,:pic,:area,:start_date,:end_date,
                     :progress,:description,:vendor,:budget,:actual,:po,:metadata,NOW(),:updated_by)'
                )->execute($values);
                recordAuditLog(
                    $pdo,
                    $project['id'],
                    (string)$user['name'],
                    sprintf('Membuat project baru %s (%s)', $project['code'], $project['name'])
                );
            }

            syncProjectChildren($pdo, $project);
            $changed = true;
        }

        foreach (is_array($push['improvements'] ?? null) ? $push['improvements'] : [] as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $id = (string)$item['id'];
            if (!isValidEntityId($id)) {
                continue;
            }
            $updatedAt = (int)($item['updatedAt'] ?? 0);
            $existing = $improvements[$id] ?? null;

            if ($user['role'] === 'contributor') {
                if (is_array($existing) && (string)($existing['submittedBy'] ?? '') !== (string)$user['name']) {
                    continue;
                }
                if (is_array($existing)) {
                    foreach (['submittedBy', 'status', 'decision', 'piOwner', 'piFeedback', 'piSuggestion', 'piAction', 'piReason', 'feedbackLog', 'projectId'] as $field) {
                        if (array_key_exists($field, $existing)) {
                            $item[$field] = $existing[$field];
                        } else {
                            unset($item[$field]);
                        }
                    }
                } else {
                    $item['submittedBy'] = $user['name'];
                    $item['status'] = 'Baru';
                    $item['decision'] = 'Pending';
                    $item['piOwner'] = $item['piFeedback'] = $item['piSuggestion'] = $item['piAction'] = $item['piReason'] = '';
                    $item['feedbackLog'] = [];
                    $item['projectId'] = '';
                }
            } elseif (is_array($existing)) {
                foreach (['title', 'department', 'submittedBy', 'submittedAt', 'type', 'issue', 'background', 'purpose', 'goals', 'savingMp', 'qualityMetric', 'attachments', 'links'] as $field) {
                    if (array_key_exists($field, $existing)) {
                        $item[$field] = $existing[$field];
                    } else {
                        unset($item[$field]);
                    }
                }
            }

            $impStatus = (string)($item['status'] ?? 'Baru');
            if (!in_array($impStatus, VALID_IMPROVEMENT_STATUSES, true)) {
                $impStatus = 'Baru';
            }
            $impDecision = (string)($item['decision'] ?? 'Pending');
            if (!in_array($impDecision, VALID_IMPROVEMENT_DECISIONS, true)) {
                $impDecision = 'Pending';
            }
            $item['status'] = $impStatus;
            $item['decision'] = $impDecision;

            if (isset($improvements[$id]) && $updatedAt > 0 && $updatedAt < (int)($improvements[$id]['updatedAt'] ?? 0)) {
                continue;
            }

            $existingImpAt = isset($improvements[$id]) ? (int)($improvements[$id]['updatedAt'] ?? 0) : 0;
            $item['updatedAt'] = max($serverNow, $updatedAt, $existingImpAt + 1);
            $item['updatedBy'] = $user['name'];
            $improvements[$id] = $item;

            recordAuditLog(
                $pdo,
                'IMPROVEMENT',
                (string)$user['name'],
                sprintf('Memperbarui improvement %s (%s) [status: %s, decision: %s]', $id, (string)($item['title'] ?? ''), $impStatus, $impDecision)
            );
            $changed = true;
        }

        if (isset($push['settings']) && is_array($push['settings']) && $user['role'] === 'admin') {
            $incoming = validateAndCleanSettings($push['settings']);
            $incomingAt = (int)($incoming['settingsAt'] ?? 0);
            $currentAt = (int)($settings['settingsAt'] ?? 0);
            if (($storedSettings === null || $incomingAt > $currentAt) && $incoming !== $settings) {
                $incoming['settingsAt'] = max($serverNow, $incomingAt, $currentAt + 1);
                writeJsonSetting('tracker', $incoming, $pdo);
                $settings = $incoming;
                recordAuditLog($pdo, 'SYSTEM', (string)$user['name'], 'Memperbarui pengaturan aplikasi');
                $changed = true;
            }
        }

        if ($changed) {
            $state['rev'] = (int)($state['rev'] ?? 0) + 1;
            $state['deleted'] = $deleted;
            $state['improvements'] = array_values($improvements);
            writeJsonSetting('tracker_state', $state, $pdo);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $revision = (int)($state['rev'] ?? 0);
    $settingsAt = (int)($settings['settingsAt'] ?? 0);
    $hasPush = !empty($push['projects']) || !empty($push['improvements']) || !empty($push['deleted']) || isset($push['settings']);
    if (!$changed && !$rejectedSettings && !$rejectedProjectAuth && !$hasPush && (int)($payload['rev'] ?? -1) === $revision) {
        jsonResponse(200, ['rev' => $revision, 'unchanged' => true]);
    }

    $visibleImprovements = $user['role'] === 'contributor'
        ? array_filter($improvements, static fn(array $item): bool => (string)($item['submittedBy'] ?? '') === (string)$user['name'])
        : $improvements;
    $response = [
        'rev' => $revision,
        'projects' => projectRows($user),
        'improvements' => array_values($visibleImprovements),
        'deleted' => $deleted,
        'settingsAt' => $settingsAt,
    ];
    if ($rejectedSettings || $settingsAt !== (int)($payload['sa'] ?? 0)) {
        $response['settings'] = $settings;
        if ($rejectedSettings) {
            $response['forceSettings'] = true;
        }
    }
    if ($rejectedProjectAuth) {
        $response['rejectedProjectAuth'] = true;
    }
    jsonResponse(200, $response);
}

if ($requestPath === '/api/events' && $method === 'GET') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    set_time_limit(30);
    echo "retry: 2000\n\n";
    $lastRevision = -1;
    $startTime = time();
    while (!connection_aborted() && (time() - $startTime) < 25) {
        $revision = (int)(syncState()['rev'] ?? 0);
        if ($revision !== $lastRevision) {
            $event = json_encode(['rev' => $revision, 'online' => [$user['name']]], JSON_UNESCAPED_SLASHES);
            echo "event: state\ndata: {$event}\n\n";
            $lastRevision = $revision;
        } else {
            echo ": ping\n\n";
        }
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        sleep(2);
    }
    exit;
}

if ($requestPath === '/api/projects' && $method === 'GET') {
    jsonResponse(200, ['items' => projectRows($user)]);
}

if ($requestPath === '/api/projects' && $method === 'POST') {
    requireProjectEditor($user);
    $data = jsonBody();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $project = normalizeProjectPayload($data['project'] ?? $data, null, $user);
        $project['metadata']['updatedAt'] = (int)round(microtime(true) * 1000);
        $project['metadata']['updatedBy'] = $user['name'];
        $project['updated_by'] = $user['name'];

        $stmt = $pdo->prepare(
            'INSERT INTO projects (id, code, name, category, status, priority, pic, area, start_date, end_date,
             progress, description, vendor, budget, actual, po, metadata, updated_at, updated_by)
             VALUES (:id, :code, :name, :category, :status, :priority, :pic, :area, :start_date, :end_date,
             :progress, :description, :vendor, :budget, :actual, :po, :metadata, NOW(), :updated_by)'
        );
        $stmt->execute([
            ':id' => $project['id'],
            ':code' => $project['code'],
            ':name' => $project['name'],
            ':category' => $project['category'],
            ':status' => $project['status'],
            ':priority' => $project['priority'],
            ':pic' => $project['pic'],
            ':area' => $project['area'],
            ':start_date' => $project['start_date'] !== '' ? $project['start_date'] : null,
            ':end_date' => $project['end_date'] !== '' ? $project['end_date'] : null,
            ':progress' => $project['progress'],
            ':description' => $project['description'],
            ':vendor' => $project['vendor'],
            ':budget' => $project['budget'],
            ':actual' => $project['actual'],
            ':po' => $project['po'],
            ':metadata' => json_encode($project['metadata'], JSON_UNESCAPED_SLASHES),
            ':updated_by' => $project['updated_by'],
        ]);

        syncProjectChildren($pdo, $project);
        recordAuditLog($pdo, $project['id'], (string)$user['name'], "Membuat project {$project['code']} - {$project['name']}");
        $state = syncState($pdo, true);
        $state['rev'] = (int)($state['rev'] ?? 0) + 1;
        writeJsonSetting('tracker_state', $state, $pdo);
        $pdo->commit();
    } catch (InvalidArgumentException $ve) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(400, ['error' => $ve->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    jsonResponse(201, ['ok' => true, 'project' => $project]);
}

if (preg_match('#^/api/projects/([^/]+)$#', $requestPath, $matches) && $method === 'PUT') {
    requireProjectEditor($user);
    $projectId = urldecode($matches[1]);
    if (!isValidEntityId($projectId)) {
        jsonResponse(400, ['error' => 'ID project tidak valid']);
    }
    $data = jsonBody();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $exists = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1 FOR UPDATE');
        $exists->execute([':id' => $projectId]);
        $existingRow = $exists->fetch();
        if (!$existingRow) {
            $pdo->rollBack();
            jsonResponse(404, ['error' => 'Project tidak ditemukan']);
        }
        if (!canModifyProject($user, $existingRow)) {
            $pdo->rollBack();
            jsonResponse(403, ['error' => 'Anda hanya dapat mengubah project yang menjadi tanggung jawab (PIC) Anda']);
        }

        $payloadInput = is_array($data['project'] ?? null) ? $data['project'] : $data;
        $payloadInput['id'] = $projectId;
        $projectData = normalizeProjectPayload($payloadInput, $existingRow, $user);
        $projectData['metadata']['updatedAt'] = (int)round(microtime(true) * 1000);
        $projectData['metadata']['updatedBy'] = $user['name'];
        $projectData['updated_by'] = $user['name'];

        $stmt = $pdo->prepare(
            'UPDATE projects SET code=:code, name=:name, category=:category, status=:status, priority=:priority,
             pic=:pic, area=:area, start_date=:start_date, end_date=:end_date, progress=:progress,
             description=:description, vendor=:vendor, budget=:budget, actual=:actual, po=:po,
             metadata=:metadata, updated_at=NOW(), updated_by=:updated_by WHERE id=:id'
        );
        $stmt->execute([
            ':id' => $projectId,
            ':code' => $projectData['code'],
            ':name' => $projectData['name'],
            ':category' => $projectData['category'],
            ':status' => $projectData['status'],
            ':priority' => $projectData['priority'],
            ':pic' => $projectData['pic'],
            ':area' => $projectData['area'],
            ':start_date' => $projectData['start_date'] !== '' ? $projectData['start_date'] : null,
            ':end_date' => $projectData['end_date'] !== '' ? $projectData['end_date'] : null,
            ':progress' => $projectData['progress'],
            ':description' => $projectData['description'],
            ':vendor' => $projectData['vendor'],
            ':budget' => $projectData['budget'],
            ':actual' => $projectData['actual'],
            ':po' => $projectData['po'],
            ':metadata' => json_encode($projectData['metadata'], JSON_UNESCAPED_SLASHES),
            ':updated_by' => $projectData['updated_by'],
        ]);

        syncProjectChildren($pdo, $projectData);
        recordAuditLog($pdo, $projectId, (string)$user['name'], "Memperbarui project {$projectData['code']} - {$projectData['name']}");
        $state = syncState($pdo, true);
        $state['rev'] = (int)($state['rev'] ?? 0) + 1;
        writeJsonSetting('tracker_state', $state, $pdo);
        $pdo->commit();
    } catch (InvalidArgumentException $ve) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(400, ['error' => $ve->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    jsonResponse(200, ['ok' => true, 'project' => $projectData]);
}

if (preg_match('#^/api/projects/([^/]+)$#', $requestPath, $matches) && $method === 'DELETE') {
    requireProjectEditor($user);
    $projectId = urldecode($matches[1]);
    if (!isValidEntityId($projectId)) {
        jsonResponse(400, ['error' => 'ID project tidak valid']);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $exists = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1 FOR UPDATE');
        $exists->execute([':id' => $projectId]);
        $existingRow = $exists->fetch();
        if (!$existingRow) {
            $pdo->rollBack();
            jsonResponse(404, ['error' => 'Project tidak ditemukan']);
        }
        if (!canDeleteProject($user, $existingRow)) {
            $pdo->rollBack();
            jsonResponse(403, ['error' => 'Hanya Admin atau pemilik/PIC project yang dapat menghapus project ini']);
        }

        $pdo->prepare('DELETE FROM project_stages WHERE project_id = :id')->execute([':id' => $projectId]);
        $pdo->prepare('DELETE FROM project_milestones WHERE project_id = :id')->execute([':id' => $projectId]);
        $pdo->prepare('DELETE FROM project_quotations WHERE project_id = :id')->execute([':id' => $projectId]);
        $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => $projectId]);
        recordAuditLog($pdo, $projectId, (string)$user['name'], "Menghapus project {$existingRow['code']} - {$existingRow['name']}");

        $state = syncState($pdo, true);
        $state['rev'] = (int)($state['rev'] ?? 0) + 1;
        $state['deleted'][$projectId] = (int)round(microtime(true) * 1000);
        writeJsonSetting('tracker_state', $state, $pdo);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/logs' && $method === 'GET') {
    requireProjectEditor($user);
    $projectId = trim((string)($_GET['project_id'] ?? ''));
    if ($projectId !== '') {
        $stmt = db()->prepare('SELECT * FROM project_logs WHERE project_id = :pid ORDER BY created_at DESC LIMIT 200');
        $stmt->execute([':pid' => $projectId]);
        $logs = $stmt->fetchAll();
    } else {
        requireAdmin($user);
        $logs = db()->query('SELECT * FROM project_logs ORDER BY created_at DESC LIMIT 500')->fetchAll();
    }
    jsonResponse(200, ['logs' => $logs]);
}

if ($requestPath === '/api/pin' && $method === 'POST') {
    requireEditor($user);
    $data = jsonBody();
    $old = (string)($data['old'] ?? '');
    $pin = (string)($data['pin'] ?? '');

    if (!password_verify($old, $user['password_hash'])) {
        jsonResponse(400, ['error' => 'PIN lama salah']);
    }
    if (!isValidPin($pin)) {
        jsonResponse(400, ['error' => 'PIN baru minimal ' . PIN_MIN_LENGTH . ' karakter']);
    }

    $pdo = db();
    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
        ':hash' => password_hash($pin, PASSWORD_DEFAULT),
        ':id' => $user['id'],
    ]);

    // Cabut seluruh sesi lain milik user ini kecuali sesi saat ini
    $currentToken = currentSessionToken();
    if ($currentToken !== null) {
        $currentHash = hash_hmac('sha256', $currentToken, SESSION_SECRET);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = :id AND token_hash <> :hash')->execute([
            ':id' => $user['id'],
            ':hash' => $currentHash,
        ]);
    } else {
        $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $user['id']]);
    }

    recordAuditLog($pdo, 'AUTH', (string)$user['name'], 'Mengganti PIN akun dan mencabut sesi perangkat lain');
    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/backup' && $method === 'POST') {
    requireAdmin($user);
    ensureStorageDirs();
    $pdo = db();
    $suffix = date('Y-m-d_His');
    $projectBackup = 'tracker-' . $suffix . '.json';
    $userBackup = 'users-' . $suffix . '.json';

    $storedFiles = [];
    if (is_dir(FILES_DIR)) {
        foreach (scandir(FILES_DIR) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === 'index.html') {
                continue;
            }
            $fp = FILES_DIR . '/' . $entry;
            if (is_file($fp)) {
                $storedFiles[] = [
                    'name' => $entry,
                    'size' => filesize($fp),
                    'sha256' => hash_file('sha256', $fp),
                ];
            }
        }
    }

    file_put_contents(BACKUP_DIR . '/' . $projectBackup, json_encode([
        'backupVersion' => 2,
        'createdAt' => date('c'),
        'createdBy' => $user['name'],
        'settings' => appSettings($pdo),
        'projects' => projectRows(),
        'state' => syncState($pdo),
        'tables' => [
            'project_files' => $pdo->query('SELECT * FROM project_files ORDER BY uploaded_at DESC')->fetchAll(),
            'project_quotations' => $pdo->query('SELECT * FROM project_quotations ORDER BY created_at DESC')->fetchAll(),
            'project_stages' => $pdo->query('SELECT * FROM project_stages ORDER BY created_at ASC')->fetchAll(),
            'project_milestones' => $pdo->query('SELECT * FROM project_milestones ORDER BY created_at ASC')->fetchAll(),
            'project_logs' => $pdo->query('SELECT * FROM project_logs ORDER BY created_at DESC LIMIT 2000')->fetchAll(),
        ],
        'attachmentsManifest' => $storedFiles,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);

    // Jangan pernah menyertakan password_hash dalam file backup
    $users = $pdo->query('SELECT id, name, role, created_at FROM users ORDER BY name ASC')->fetchAll();
    file_put_contents(BACKUP_DIR . '/' . $userBackup, json_encode([
        'backupVersion' => 2,
        'createdAt' => date('c'),
        'users' => $users,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);

    recordAuditLog($pdo, 'SYSTEM', (string)$user['name'], "Membuat backup aplikasi: {$projectBackup}, {$userBackup}");
    jsonResponse(200, ['files' => [$projectBackup, $userBackup]]);
}

if ($requestPath === '/api/restore' && $method === 'POST') {
    requireAdmin($user);
    ensureStorageDirs();
    $data = jsonBody();

    if (isset($data['file']) && is_string($data['file'])) {
        $backupFile = safeName($data['file']);
        $backupPath = BACKUP_DIR . '/' . $backupFile;
        if (!is_file($backupPath)) {
            jsonResponse(404, ['error' => 'File backup tidak ditemukan']);
        }
        $decoded = json_decode((string)file_get_contents($backupPath), true);
        if (!is_array($decoded)) {
            jsonResponse(400, ['error' => 'Isi file backup tidak valid']);
        }
        $data = $decoded;
    }

    if (!isset($data['projects']) || !is_array($data['projects'])) {
        jsonResponse(400, ['error' => 'Payload restore harus memuat daftar projects']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $restoredCount = 0;
        foreach ($data['projects'] as $item) {
            if (!is_array($item) || empty($item['id']) || !isValidEntityId((string)$item['id'])) {
                continue;
            }
            $project = normalizeProjectPayload($item, null, $user);
            $project['metadata']['updatedAt'] = (int)($item['updatedAt'] ?? round(microtime(true) * 1000));
            $project['metadata']['updatedBy'] = (string)($item['updatedBy'] ?? $user['name']);
            $stmt = $pdo->prepare(
                'INSERT INTO projects (id, code, name, category, status, priority, pic, area, start_date, end_date,
                 progress, description, vendor, budget, actual, po, metadata, updated_at, updated_by)
                 VALUES (:id, :code, :name, :category, :status, :priority, :pic, :area, :start_date, :end_date,
                 :progress, :description, :vendor, :budget, :actual, :po, :metadata, NOW(), :updated_by)
                 ON DUPLICATE KEY UPDATE
                 code=VALUES(code), name=VALUES(name), category=VALUES(category), status=VALUES(status),
                 priority=VALUES(priority), pic=VALUES(pic), area=VALUES(area), start_date=VALUES(start_date),
                 end_date=VALUES(end_date), progress=VALUES(progress), description=VALUES(description),
                 vendor=VALUES(vendor), budget=VALUES(budget), actual=VALUES(actual), po=VALUES(po),
                 metadata=VALUES(metadata), updated_at=NOW(), updated_by=VALUES(updated_by)'
            );
            $stmt->execute([
                ':id' => $project['id'],
                ':code' => $project['code'],
                ':name' => $project['name'],
                ':category' => $project['category'],
                ':status' => $project['status'],
                ':priority' => $project['priority'],
                ':pic' => $project['pic'],
                ':area' => $project['area'],
                ':start_date' => $project['start_date'] !== '' ? $project['start_date'] : null,
                ':end_date' => $project['end_date'] !== '' ? $project['end_date'] : null,
                ':progress' => $project['progress'],
                ':description' => $project['description'],
                ':vendor' => $project['vendor'],
                ':budget' => $project['budget'],
                ':actual' => $project['actual'],
                ':po' => $project['po'],
                ':metadata' => json_encode($project['metadata'], JSON_UNESCAPED_SLASHES),
                ':updated_by' => $project['updated_by'],
            ]);
            syncProjectChildren($pdo, $project);
            $restoredCount++;
        }

        if (isset($data['settings']) && is_array($data['settings'])) {
            writeJsonSetting('tracker', validateAndCleanSettings($data['settings']), $pdo);
        }

        $state = syncState($pdo, true);
        if (isset($data['state']) && is_array($data['state'])) {
            if (isset($data['state']['improvements']) && is_array($data['state']['improvements'])) {
                $state['improvements'] = $data['state']['improvements'];
            }
            if (isset($data['state']['deleted']) && is_array($data['state']['deleted'])) {
                $state['deleted'] = $data['state']['deleted'];
            }
        }
        $state['rev'] = (int)($state['rev'] ?? 0) + 1;
        writeJsonSetting('tracker_state', $state, $pdo);

        recordAuditLog($pdo, 'SYSTEM', (string)$user['name'], "Restore backup berhasil ({$restoredCount} project dipulihkan)");
        $pdo->commit();
    } catch (InvalidArgumentException $ve) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(400, ['error' => $ve->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    jsonResponse(200, ['ok' => true, 'restoredProjects' => $restoredCount, 'rev' => $state['rev']]);
}

if ($requestPath === '/api/files' && $method === 'GET') {
    requireProjectEditor($user);
    $rows = db()->query('SELECT f.id, f.project_id, f.file_name, f.mime_type, f.size_bytes, f.stored_name, f.uploaded_at, p.code AS project_code, p.name AS project_name FROM project_files f LEFT JOIN projects p ON p.id = f.project_id ORDER BY f.uploaded_at DESC')->fetchAll();
    jsonResponse(200, ['files' => $rows]);
}

if ($requestPath === '/api/files' && $method === 'POST') {
    requireEditor($user);
    ensureStorageDirs();
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $rawUpload = str_starts_with($contentType, 'application/octet-stream');
    $filename = basename(rawurldecode((string)($_SERVER['HTTP_X_FILENAME'] ?? $_FILES['file']['name'] ?? 'file')));
    $prefix = rawurldecode((string)($_SERVER['HTTP_X_PREFIX'] ?? 'PRJ'));

    if ($user['role'] === 'contributor' && preg_match('/^IMP-[A-Za-z0-9_-]+$/', $prefix) !== 1) {
        jsonResponse(403, ['error' => 'Akses Kontributor hanya dapat mengunggah lampiran Improvement']);
    }

    // Validasi ekstensi file terhadap whitelist dan tolak ekstensi berbahaya/ganda
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '' || !array_key_exists($ext, ALLOWED_UPLOAD_MIMES)) {
        jsonResponse(400, ['error' => 'Tipe file tidak diizinkan. Gunakan gambar, PDF, video MP4/WebM/MOV, dokumen Office, CSV, atau TXT.']);
    }
    if (preg_match('/\.(php\d*|phtml|phar|cgi|pl|py|sh|bat|cmd|exe|dll|jsp|asp|aspx|htaccess|htpasswd|html?|svg|js)(\.|$)/i', $filename) === 1) {
        jsonResponse(400, ['error' => 'Nama atau ekstensi file berbahaya ditolak oleh kebijakan keamanan']);
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > MAX_UPLOAD_BYTES) {
        jsonResponse(413, ['error' => 'Ukuran file melebihi batas maksimal ' . MAX_UPLOAD_MB . ' MB']);
    }

    $fileToken = base_convert((string)(int)round(microtime(true) * 1000), 10, 36) . '_' . bin2hex(random_bytes(3));
    $baseWithoutExt = safeName(substr((string)pathinfo($filename, PATHINFO_FILENAME), 0, 120));
    $safePrefix = safeName(substr($prefix, 0, 40));
    $safeFile = "{$safePrefix}_{$fileToken}_{$baseWithoutExt}.{$ext}";

    $projectId = trim((string)($_POST['project_id'] ?? ''));
    if (!$rawUpload && $projectId === '') {
        jsonResponse(400, ['error' => 'Pilih project untuk lampiran']);
    }
    if ($rawUpload && $projectId === '') {
        $projectCheck = db()->prepare('SELECT id FROM projects WHERE code = :code LIMIT 1');
        $projectCheck->execute([':code' => $prefix]);
        $projectId = (string)($projectCheck->fetchColumn() ?: '');
    }

    $target = FILES_DIR . '/' . $safeFile;
    if ($rawUpload) {
        $input = fopen('php://input', 'rb');
        $output = fopen($target, 'wb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            jsonResponse(500, ['error' => 'Gagal membuka stream file']);
        }
        $written = 0;
        $exceeded = false;
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            if ($written > MAX_UPLOAD_BYTES) {
                $exceeded = true;
                break;
            }
            fwrite($output, $chunk);
        }
        fclose($input);
        fclose($output);
        if ($exceeded) {
            @unlink($target);
            jsonResponse(413, ['error' => 'Ukuran file melebihi batas maksimal ' . MAX_UPLOAD_MB . ' MB']);
        }
    } else {
        $fileErr = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
        $fileSize = (int)($_FILES['file']['size'] ?? 0);
        if ($fileSize > MAX_UPLOAD_BYTES) {
            jsonResponse(413, ['error' => 'Ukuran file melebihi batas maksimal ' . MAX_UPLOAD_MB . ' MB']);
        }
        $tmp = $_FILES['file']['tmp_name'] ?? null;
        if ($fileErr !== UPLOAD_ERR_OK || !$tmp || !is_uploaded_file($tmp) || !move_uploaded_file($tmp, $target)) {
            jsonResponse(400, ['error' => 'File unggahan tidak valid']);
        }
    }

    if (!is_file($target)) {
        jsonResponse(500, ['error' => 'Gagal menyimpan file']);
    }

    $size = (int)filesize($target);
    if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
        @unlink($target);
        jsonResponse(400, ['error' => 'Ukuran file kosong atau melebihi batas']);
    }

    // Validasi konten terhadap script PHP berbahaya dan kesesuaian MIME type
    $headBytes = (string)file_get_contents($target, false, null, 0, 4096);
    if (preg_match('/<\?(?:php|=)/i', $headBytes) === 1) {
        @unlink($target);
        jsonResponse(400, ['error' => 'Konten file mengandung script yang dilarang']);
    }

    $detectedMime = strtolower((string)(mime_content_type($target) ?: 'application/octet-stream'));
    $allowedMimesForExt = ALLOWED_UPLOAD_MIMES[$ext];
    if (!in_array($detectedMime, $allowedMimesForExt, true)) {
        @unlink($target);
        jsonResponse(400, ['error' => "Konten file ({$detectedMime}) tidak sesuai dengan ekstensi .{$ext}"]);
    }

    $pdo = db();
    if ($projectId !== '') {
        $projectCheck = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $projectCheck->execute([':id' => $projectId]);
        $projRow = $projectCheck->fetch();
        if ($projRow) {
            if (!canModifyProject($user, $projRow)) {
                @unlink($target);
                jsonResponse(403, ['error' => 'Anda tidak memiliki akses ke project ini']);
            }
            $insert = $pdo->prepare('INSERT INTO project_files (id, project_id, file_name, mime_type, size_bytes, stored_name, uploaded_at) VALUES (:id, :project_id, :file_name, :mime_type, :size_bytes, :stored_name, NOW())');
            $insert->execute([
                ':id' => uuid4(),
                ':project_id' => $projectId,
                ':file_name' => $filename,
                ':mime_type' => $detectedMime,
                ':size_bytes' => $size,
                ':stored_name' => $safeFile,
            ]);
        } elseif (!$rawUpload) {
            @unlink($target);
            jsonResponse(400, ['error' => 'Project tidak ditemukan']);
        }
    }

    recordAuditLog($pdo, $projectId !== '' ? $projectId : 'FILE', (string)$user['name'], "Mengunggah file {$safeFile} ({$size} bytes)");
    jsonResponse(200, ['name' => $safeFile, 'size' => $size, 'mime_type' => $detectedMime]);
}

if (preg_match('#^/api/files/(.+)$#', $requestPath, $matches) && $method === 'GET') {
    $name = rawurldecode($matches[1]);
    $safe = safeName($name);
    if ($user['role'] === 'contributor' && !str_starts_with($safe, 'IMP-')) {
        jsonResponse(403, ['error' => 'Akses Kontributor hanya dapat membuka lampiran Improvement']);
    }
    $target = FILES_DIR . '/' . $safe;
    if (!is_file($target)) {
        jsonResponse(404, ['error' => 'File tidak ditemukan']);
    }
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $inlineMimes = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
        'txt' => 'text/plain; charset=utf-8',
    ];
    $isInline = isset($inlineMimes[$ext]);
    $contentType = $inlineMimes[$ext] ?? 'application/octet-stream';
    $disposition = $isInline ? 'inline' : 'attachment';

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($target));
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($safe) . '"');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; media-src 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    readfile($target);
    exit;
}

if (preg_match('#^/api/files/(.+)$#', $requestPath, $matches) && $method === 'DELETE') {
    requireEditor($user);
    requireAdmin($user);
    $storedName = safeName(rawurldecode($matches[1]));
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, project_id FROM project_files WHERE stored_name = :stored_name LIMIT 1');
    $stmt->execute([':stored_name' => $storedName]);
    $file = $stmt->fetch();
    if (!$file) {
        jsonResponse(404, ['error' => 'File tidak ditemukan']);
    }

    $pdo->prepare('DELETE FROM project_files WHERE id = :id')->execute([':id' => $file['id']]);
    $target = FILES_DIR . '/' . $storedName;
    if (is_file($target)) {
        @unlink($target);
    }
    recordAuditLog($pdo, (string)($file['project_id'] ?? 'FILE'), (string)$user['name'], "Menghapus file {$storedName}");
    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/export' && $method === 'GET') {
    requireProjectEditor($user);
    $rows = db()->query('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll();
    $blob = json_encode(array_map('projectToOutput', $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="tracker-data-' . date('Y-m-d') . '.json"');
    echo $blob;
    exit;
}

jsonResponse(404, ['error' => 'Tidak ditemukan']);
