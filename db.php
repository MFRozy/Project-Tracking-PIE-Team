<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const VALID_PROJECT_STATUSES = ['Not Started', 'On Progress', 'On Hold', 'Completed', 'Delayed'];
const VALID_PROJECT_PRIORITIES = ['Low', 'Medium', 'High'];
const VALID_IMPROVEMENT_STATUSES = ['Baru', 'Review PI', 'Menunggu Data', 'Disetujui', 'Tidak Perlu Improvement', 'Selesai', 'Ditolak'];
const VALID_IMPROVEMENT_DECISIONS = ['Pending', 'To Project', 'Suggestion Only'];

const ALLOWED_UPLOAD_MIMES = [
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'pdf'  => ['application/pdf'],
    'mp4'  => ['video/mp4'],
    'webm' => ['video/webm'],
    'mov'  => ['video/quicktime', 'video/mp4'],
    'm4v'  => ['video/x-m4v', 'video/mp4'],
    'txt'  => ['text/plain'],
    'csv'  => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
    'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/octet-stream'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
];

function ensureStorageDirs(): void
{
    $htaccessRule = <<<'HTACCESS'
Options -Indexes -ExecCGI
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py .asp .aspx .shtml .sh
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py .asp .aspx .shtml .sh
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS;

    foreach ([STORAGE_DIR, FILES_DIR, BACKUP_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $htFile = $dir . '/.htaccess';
        if (!is_file($htFile)) {
            @file_put_contents($htFile, $htaccessRule . "\n", LOCK_EX);
        }
        $idxFile = $dir . '/index.html';
        if (!is_file($idxFile)) {
            @file_put_contents($idxFile, '', LOCK_EX);
        }
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    static $schemaChecked = false;
    if (!$schemaChecked) {
        $schemaChecked = true;
        $roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        $roleType = strtolower((string)($roleColumn['Type'] ?? ''));
        if ($roleColumn && (!str_contains($roleType, "'viewer'") || !str_contains($roleType, "'contributor'"))) {
            $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin', 'user', 'viewer', 'contributor') NOT NULL DEFAULT 'user'");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            login_key CHAR(64) NOT NULL,
            failed_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (login_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // P0-2: Pre-populate settings rows outside request transactions to eliminate gap lock deadlocks
        $pdo->exec("INSERT INTO settings (key_name, value_json) VALUES
            ('tracker', '{\"appName\":\"PI Project Management\",\"currency\":\"IDR\",\"settingsAt\":0}'),
            ('tracker_state', '{\"rev\":0,\"deleted\":[],\"improvements\":[]}')
            ON DUPLICATE KEY UPDATE key_name = key_name");

        // P0-4: Ensure sessions table has last_seen_at for idle timeout
        try {
            $hasLastSeen = (bool)$pdo->query("SHOW COLUMNS FROM sessions LIKE 'last_seen_at'")->fetch();
            if (!$hasLastSeen) {
                $pdo->exec("ALTER TABLE sessions ADD COLUMN last_seen_at DATETIME NULL AFTER expires_at");
            }
        } catch (Throwable $_) {
        }
    }

    return $pdo;
}

function uuid4(): string
{
    return sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
}

function isValidEntityId(mixed $id): bool
{
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,36}$/', $id) === 1;
}

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
        return true;
    }

    // P0-4: Trust X-Forwarded-Proto only from configured trusted proxies
    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
    if (!empty($trustedProxies) && in_array($remoteIp, $trustedProxies, true)) {
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
    }

    return false;
}

function shouldUseSecureCookie(): bool
{
    $mode = strtolower(COOKIE_SECURE_MODE);
    if ($mode === 'true' || $mode === '1') {
        return true;
    }
    if ($mode === 'false' || $mode === '0') {
        return false;
    }
    return isHttpsRequest();
}

function currentSessionToken(): ?string
{
    $token = $_COOKIE[SESSION_COOKIE] ?? null;
    if (!is_string($token) || trim($token) === '') {
        return null;
    }
    return trim($token);
}

function csrfTokenForSession(string $sessionToken): string
{
    return hash_hmac('sha256', 'csrf:' . $sessionToken, SESSION_SECRET);
}

function currentCsrfToken(): string
{
    $token = currentSessionToken();
    return $token !== null ? csrfTokenForSession($token) : '';
}

function verifyCsrfRequest(bool $requireCsrfToken = true): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    if ((string)($_SERVER['HTTP_X_PT'] ?? '') !== '1') {
        jsonResponse(403, ['error' => 'Permintaan tidak valid (header X-PT wajib disertakan)']);
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $hdr) {
        $val = trim((string)($_SERVER[$hdr] ?? ''));
        if ($val !== '' && $host !== '') {
            $parsedHost = strtolower((string)parse_url($val, PHP_URL_HOST));
            $parsedPort = parse_url($val, PHP_URL_PORT);
            $originHostPort = $parsedPort ? "{$parsedHost}:{$parsedPort}" : $parsedHost;
            if ($parsedHost !== '' && $parsedHost !== $host && $originHostPort !== $host) {
                jsonResponse(403, ['error' => 'Origin permintaan tidak diizinkan']);
            }
        }
    }

    if ($requireCsrfToken) {
        $expected = currentCsrfToken();
        $provided = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            jsonResponse(403, ['error' => 'Token keamanan CSRF tidak valid atau kadaluarsa. Silakan muat ulang halaman.']);
        }
    }
}

function currentUser(): ?array
{
    $token = currentSessionToken();
    if ($token === null) {
        return null;
    }

    $hash = hash_hmac('sha256', $token, SESSION_SECRET);
    $stmt = db()->prepare(
        'SELECT u.*, s.id AS session_id,
                TIMESTAMPDIFF(SECOND, COALESCE(s.last_seen_at, s.created_at), NOW()) AS idle_seconds
         FROM sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = :hash AND s.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    // P0-4: Check idle timeout (default 30 min)
    $idleTimeout = defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 1800;
    $idleSeconds = (int)($row['idle_seconds'] ?? 0);
    if ($idleSeconds > $idleTimeout) {
        db()->prepare('DELETE FROM sessions WHERE id = :id')->execute([':id' => $row['session_id']]);
        return null;
    }

    // P0-4: Throttle last_seen_at update to at most once a minute
    if ($idleSeconds >= 60) {
        db()->prepare('UPDATE sessions SET last_seen_at = NOW() WHERE id = :id')->execute([':id' => $row['session_id']]);
    }

    unset($row['session_id'], $row['idle_seconds']);
    return $row;
}

function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        jsonResponse(401, ['error' => 'Belum masuk']);
    }

    return $user;
}

function requireAdmin(array $user): void
{
    if (($user['role'] ?? 'user') !== 'admin') {
        jsonResponse(403, ['error' => 'Hanya admin']);
    }
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonResponse(400, ['error' => 'Format JSON tidak valid']);
    }
    return $decoded;
}

function jsonResponse(int $status, mixed $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function setSessionCookie(string $token): void
{
    setcookie(
        SESSION_COOKIE,
        $token,
        [
            'expires' => time() + SESSION_LIFETIME,
            'path' => '/',
            'secure' => shouldUseSecureCookie(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
    $_COOKIE[SESSION_COOKIE] = $token;
}

function clearSessionCookie(): void
{
    setcookie(
        SESSION_COOKIE,
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => shouldUseSecureCookie(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
    unset($_COOKIE[SESSION_COOKIE]);
}

function userPublicArray(array $user): array
{
    return [
        'id' => $user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
        'created_at' => $user['created_at'],
    ];
}

function normalizeDateString(mixed $value, string $fieldName = 'Tanggal'): string
{
    $str = trim((string)($value ?? ''));
    if ($str === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str, $m) !== 1) {
        throw new InvalidArgumentException("{$fieldName} harus berformat YYYY-MM-DD");
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException("{$fieldName} tidak valid");
    }
    return $str;
}

function normalizeProjectStatus(string $status): string
{
    $trimmed = trim($status);
    if (strcasecmp($trimmed, 'In Progress') === 0 || $trimmed === '') {
        return 'On Progress';
    }
    foreach (VALID_PROJECT_STATUSES as $valid) {
        if (strcasecmp($trimmed, $valid) === 0) {
            return $valid;
        }
    }
    throw new InvalidArgumentException('Status project tidak valid: ' . $trimmed);
}

function normalizeProjectPriority(string $priority): string
{
    $trimmed = trim($priority);
    if ($trimmed === '') {
        return 'Medium';
    }
    foreach (VALID_PROJECT_PRIORITIES as $valid) {
        if (strcasecmp($trimmed, $valid) === 0) {
            return $valid;
        }
    }
    throw new InvalidArgumentException('Prioritas project tidak valid: ' . $trimmed);
}

const VALID_QUOTATION_STATUSES = ['Diterima', 'Review', 'Terpilih', 'Ditolak'];
const VALID_STAGE_STATUSES = ['Not Started', 'On Progress', 'On Hold', 'Completed'];
const VALID_MILESTONE_STATUSES = ['Pending', 'Completed'];
const VALID_CURRENCIES = ['IDR', 'USD', 'EUR', 'JPY', 'SGD', 'KRW', 'CNY', 'THB', 'GBP', 'AUD', 'MYR', 'INR'];
const VALID_IMPROVEMENT_TYPES = ['saving_mp', 'quality', 'efficiency', 'other'];
const MAX_NESTED_ITEMS = 200;

function isValidNestedId(mixed $id): bool
{
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
}

/**
 * Validate one value against a rule. Rules: id | optid | text | mtext | enum | num | int | date | url | file | mime | bool.
 * Throws InvalidArgumentException (-> HTTP 400) instead of silently coercing.
 */
function cleanNestedValue(mixed $v, array $rule, string $label): mixed
{
    $type = $rule['t'];
    $max = (int)($rule['max'] ?? 255);
    switch ($type) {
        case 'id':
        case 'optid':
            if ($v === null || $v === '') {
                return '';
            }
            if (!isValidNestedId($v)) {
                throw new InvalidArgumentException("ID {$label} tidak valid");
            }
            return $v;
        case 'text':
        case 'mtext':
            if ($v === null) {
                return '';
            }
            if (!is_string($v) && !is_int($v) && !is_float($v)) {
                throw new InvalidArgumentException("{$label} harus berupa teks");
            }
            $s = (string)$v;
            if (!mb_check_encoding($s, 'UTF-8')) {
                throw new InvalidArgumentException("{$label} mengandung karakter tidak valid");
            }
            $controls = $type === 'mtext' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
            if (preg_match($controls, $s) === 1) {
                throw new InvalidArgumentException("{$label} mengandung karakter kontrol");
            }
            if (mb_strlen($s) > $max) {
                throw new InvalidArgumentException("{$label} terlalu panjang (maksimal {$max} karakter)");
            }
            return $type === 'text' ? trim($s) : $s;
        case 'enum':
            if ($v === null || $v === '') {
                return '';
            }
            if (!is_string($v) || !in_array($v, $rule['values'], true)) {
                throw new InvalidArgumentException("{$label} tidak valid");
            }
            return $v;
        case 'num':
        case 'int':
            if ($v === null || $v === '') {
                return 0;
            }
            if (is_bool($v) || !(is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)))) {
                throw new InvalidArgumentException("{$label} harus berupa angka");
            }
            $n = $v + 0;
            $lo = $rule['min'] ?? 0;
            $hi = $rule['hi'] ?? 1e15;
            if (is_float($n) && !is_finite($n) || $n < $lo || $n > $hi) {
                throw new InvalidArgumentException("{$label} di luar rentang yang diizinkan");
            }
            return $type === 'int' ? (int)$n : $n;
        case 'date':
            return normalizeDateString($v, $label);
        case 'url':
            if ($v === null || $v === '') {
                return '';
            }
            if (!is_string($v) || strlen($v) > 2000 || preg_match('#^https?://[^\s<>"\\\\\x00-\x1F\x7F]+$#i', $v) !== 1 || parse_url($v, PHP_URL_HOST) === null) {
                throw new InvalidArgumentException("{$label} harus berupa tautan http:// atau https:// yang valid");
            }
            return $v;
        case 'file':
            if ($v === null || $v === '') {
                return '';
            }
            if (!is_string($v) || strlen($v) > 255 || preg_match('/^[A-Za-z0-9._-]+_[a-z0-9]+_[0-9a-f]{6}_[A-Za-z0-9._-]+$/', $v) !== 1) {
                throw new InvalidArgumentException("Referensi file {$label} tidak valid");
            }
            return $v;
        case 'mime':
            if ($v === null || $v === '') {
                return '';
            }
            if (!is_string($v) || strlen($v) > 120 || preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#', $v) !== 1) {
                throw new InvalidArgumentException("{$label} tidak valid");
            }
            return $v;
        case 'bool':
            if (is_bool($v)) {
                return $v;
            }
            if ($v === null || $v === 0 || $v === '0' || $v === '' || $v === 'false') {
                return false;
            }
            if ($v === 1 || $v === '1' || $v === 'true') {
                return true;
            }
            throw new InvalidArgumentException("{$label} tidak valid");
    }
    throw new LogicException('Unknown rule ' . $type);
}

/**
 * Validate an object against a field spec. Known keys are checked strictly; unknown keys survive only as
 * short, control-free scalars (nested arrays/objects under unknown keys are dropped).
 */
function cleanNestedObject(array $obj, array $spec, string $label, bool $needsId = false): array
{
    $out = [];
    foreach ($spec as $key => $rule) {
        if (!array_key_exists($key, $obj)) {
            continue;
        }
        if (($rule['t'] ?? '') === 'list') {
            $out[$key] = cleanNestedList($obj[$key], $rule['spec'] ?? [], "{$label}.{$key}", (bool)($rule['ids'] ?? true), $rule['item'] ?? null);
            continue;
        }
        $out[$key] = cleanNestedValue($obj[$key], $rule, "{$label}.{$key}");
    }
    foreach ($obj as $key => $value) {
        if (isset($spec[$key]) || !is_string($key) || preg_match('/^[A-Za-z0-9_]{1,40}$/', $key) !== 1 || is_array($value)) {
            continue;
        }
        if (is_string($value)) {
            $out[$key] = cleanNestedValue($value, ['t' => 'mtext', 'max' => 2000], "{$label}.{$key}");
        } elseif (is_int($value) || is_bool($value) || $value === null || (is_float($value) && is_finite($value))) {
            $out[$key] = $value;
        }
    }
    if ($needsId && (!isset($out['id']) || $out['id'] === '')) {
        $out['id'] = uuid4();
    }
    return $out;
}

/** Validate a list of objects (or of plain text lines when $item = 'text'). */
function cleanNestedList(mixed $list, array $spec, string $label, bool $needsId = true, ?array $item = null): array
{
    if ($list === null) {
        return [];
    }
    if (!is_array($list)) {
        throw new InvalidArgumentException("{$label} harus berupa daftar");
    }
    if (count($list) > MAX_NESTED_ITEMS) {
        throw new InvalidArgumentException("{$label} terlalu banyak entri (maksimal " . MAX_NESTED_ITEMS . ')');
    }
    $out = [];
    foreach ($list as $i => $entry) {
        if ($item !== null) {
            $out[] = cleanNestedValue($entry, $item, "{$label}[{$i}]");
            continue;
        }
        if (!is_array($entry)) {
            continue;
        }
        $out[] = cleanNestedObject($entry, $spec, "{$label}[{$i}]", $needsId);
    }
    return $out;
}

function attachmentSpec(): array
{
    return [
        'id' => ['t' => 'id'], 'name' => ['t' => 'text', 'max' => 255], 'file' => ['t' => 'file'], 'size' => ['t' => 'num', 'hi' => 1e12],
        'type' => ['t' => 'mime'], 'uploadedAt' => ['t' => 'int'], 'uploadedBy' => ['t' => 'text', 'max' => 100],
    ];
}

function linkSpec(): array
{
    return ['id' => ['t' => 'id'], 'title' => ['t' => 'text', 'max' => 255], 'url' => ['t' => 'url']];
}

function nestedProjectSpecs(): array
{
    $cur = ['t' => 'enum', 'values' => VALID_CURRENCIES];
    return [
        'quotations' => [
            'id' => ['t' => 'id'], 'vendor' => ['t' => 'text', 'max' => 255], 'amount' => ['t' => 'num'], 'currency' => $cur,
            'status' => ['t' => 'enum', 'values' => VALID_QUOTATION_STATUSES], 'prevStatus' => ['t' => 'enum', 'values' => VALID_QUOTATION_STATUSES],
            'date' => ['t' => 'date'], 'valid' => ['t' => 'date'], 'no' => ['t' => 'text', 'max' => 120], 'link' => ['t' => 'url'],
            'file' => ['t' => 'file'], 'fileName' => ['t' => 'text', 'max' => 255], 'note' => ['t' => 'mtext', 'max' => 5000],
        ],
        'milestones' => [
            'id' => ['t' => 'id'], 'name' => ['t' => 'text', 'max' => 160], 'date' => ['t' => 'date'], 'done' => ['t' => 'bool'],
            'status' => ['t' => 'enum', 'values' => VALID_MILESTONE_STATUSES], 'note' => ['t' => 'mtext', 'max' => 5000],
        ],
        'stages' => [
            'id' => ['t' => 'id'], 'name' => ['t' => 'text', 'max' => 160], 'pic' => ['t' => 'text', 'max' => 100],
            'status' => ['t' => 'enum', 'values' => VALID_STAGE_STATUSES], 'prog' => ['t' => 'num', 'hi' => 100],
            'note' => ['t' => 'mtext', 'max' => 5000], 'par' => ['t' => 'bool'], 'days' => ['t' => 'num', 'hi' => 3650],
            'attachments' => ['t' => 'list', 'spec' => attachmentSpec()],
            'docLinks' => ['t' => 'list', 'spec' => linkSpec()],
        ],
        'pos' => [
            'id' => ['t' => 'id'], 'no' => ['t' => 'text', 'max' => 120], 'date' => ['t' => 'date'], 'qid' => ['t' => 'optid'],
            'vendor' => ['t' => 'text', 'max' => 255], 'currency' => $cur, 'addr' => ['t' => 'mtext', 'max' => 1000],
            'attn' => ['t' => 'text', 'max' => 255], 'qno' => ['t' => 'text', 'max' => 120], 'subject' => ['t' => 'text', 'max' => 255],
            'items' => ['t' => 'list', 'spec' => [
                'id' => ['t' => 'id'], 'desc' => ['t' => 'mtext', 'max' => 1000], 'qty' => ['t' => 'num'], 'unit' => ['t' => 'text', 'max' => 50],
                'price' => ['t' => 'num'], 'due' => ['t' => 'date'],
            ]],
            'tax' => ['t' => 'num', 'hi' => 100], 'terms' => ['t' => 'mtext', 'max' => 5000], 'delivery' => ['t' => 'text', 'max' => 255],
            'note' => ['t' => 'mtext', 'max' => 5000], 'by' => ['t' => 'text', 'max' => 100], 'at' => ['t' => 'int'],
            'shipName' => ['t' => 'text', 'max' => 255], 'shipAddr' => ['t' => 'mtext', 'max' => 1000], 'shipTel' => ['t' => 'text', 'max' => 120],
            'shipAttn' => ['t' => 'text', 'max' => 255], 'leadTime' => ['t' => 'text', 'max' => 120], 'tel' => ['t' => 'text', 'max' => 120],
            'email' => ['t' => 'text', 'max' => 255], 'useSig' => ['t' => 'bool'],
        ],
    ];
}

/** Validate every nested object stored in project metadata. Stage-specific rules (dates, completion) stay in the caller. */
function validateProjectNested(array $metadata): array
{
    $specs = nestedProjectSpecs();
    foreach (['quotations', 'milestones', 'pos'] as $key) {
        if (array_key_exists($key, $metadata)) {
            $metadata[$key] = cleanNestedList($metadata[$key], $specs[$key], $key);
        }
    }
    if (array_key_exists('log', $metadata)) {
        $metadata['log'] = cleanNestedList($metadata['log'], [
            't' => ['t' => 'int'], 'by' => ['t' => 'text', 'max' => 100],
            'items' => ['t' => 'list', 'spec' => [], 'item' => ['t' => 'text', 'max' => 500]],
        ], 'log', false);
    }
    return $metadata;
}

/** Validate an improvement record (all nested objects). Throws InvalidArgumentException -> HTTP 400. */
function normalizeImprovementPayload(array $item): array
{
    $long = ['t' => 'mtext', 'max' => 5000];
    $spec = [
        'id' => ['t' => 'id'], 'title' => ['t' => 'text', 'max' => 200], 'department' => ['t' => 'text', 'max' => 100],
        'submittedBy' => ['t' => 'text', 'max' => 100], 'submittedAt' => ['t' => 'int'],
        'type' => ['t' => 'enum', 'values' => VALID_IMPROVEMENT_TYPES],
        'issue' => $long, 'background' => $long, 'purpose' => $long, 'goals' => $long,
        'savingMp' => ['t' => 'text', 'max' => 200], 'qualityMetric' => ['t' => 'text', 'max' => 200],
        'attachments' => ['t' => 'list', 'spec' => attachmentSpec()],
        'links' => ['t' => 'list', 'spec' => linkSpec()],
        'status' => ['t' => 'enum', 'values' => VALID_IMPROVEMENT_STATUSES],
        'decision' => ['t' => 'enum', 'values' => VALID_IMPROVEMENT_DECISIONS],
        'piOwner' => ['t' => 'text', 'max' => 100], 'piFeedback' => $long, 'piSuggestion' => $long, 'piAction' => $long, 'piReason' => $long,
        'feedbackLog' => ['t' => 'list', 'ids' => false, 'spec' => [
            'by' => ['t' => 'text', 'max' => 100], 'at' => ['t' => 'int'],
            'status' => ['t' => 'enum', 'values' => VALID_IMPROVEMENT_STATUSES], 'decision' => ['t' => 'enum', 'values' => VALID_IMPROVEMENT_DECISIONS],
            'feedback' => $long, 'suggestion' => $long, 'action' => $long, 'reason' => $long,
        ]],
        'projectId' => ['t' => 'optid'], 'updatedAt' => ['t' => 'int'], 'updatedBy' => ['t' => 'text', 'max' => 100],
    ];
    return cleanNestedObject($item, $spec, 'improvement');
}

/** Reject non-text or control characters in a top-level project text field (length handling stays with the caller). */
function assertPlainProjectText(array $data, array $keys, string $multilineKey = ''): void
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            cleanNestedValue($data[$key], ['t' => $key === $multilineKey ? 'mtext' : 'text', 'max' => 1000000], "Kolom {$key}");
        }
    }
}

function normalizeProjectPayload(array $data, ?array $existingRow = null, ?array $actor = null): array
{
    $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
    $projectFields = [
        'id', 'code', 'name', 'category', 'status', 'priority', 'pic', 'area',
        'start', 'start_date', 'end', 'end_date', 'progress', 'desc', 'description',
        'vendor', 'budget', 'actual', 'po', 'updatedAt', 'updated_at', 'updatedBy',
        'updated_by', 'metadata',
    ];
    foreach ($data as $key => $value) {
        if (!in_array($key, $projectFields, true)) {
            $metadata[$key] = $value;
        }
    }

    $existingMeta = [];
    if ($existingRow !== null && isset($existingRow['metadata'])) {
        $decodedMeta = json_decode((string)$existingRow['metadata'], true);
        if (is_array($decodedMeta)) {
            $existingMeta = $decodedMeta;
        }
    }
    if ($existingRow !== null) {
        if (array_key_exists('improvementId', $existingMeta)) {
            $metadata['improvementId'] = $existingMeta['improvementId'];
        } else {
            unset($metadata['improvementId']);
        }
    }

    $rawId = trim((string)($data['id'] ?? ($existingRow['id'] ?? uuid4())));
    if (!isValidEntityId($rawId)) {
        throw new InvalidArgumentException('ID project tidak valid');
    }

    $name = trim((string)($data['name'] ?? ($existingRow['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 255) {
        throw new InvalidArgumentException('Nama project wajib diisi (maksimal 255 karakter)');
    }

    $status = normalizeProjectStatus((string)($data['status'] ?? ($existingRow['status'] ?? 'On Progress')));
    $priority = normalizeProjectPriority((string)($data['priority'] ?? ($existingRow['priority'] ?? 'Medium')));

    $startDate = normalizeDateString($data['start_date'] ?? $data['start'] ?? ($existingRow['start_date'] ?? ''), 'Tanggal mulai');
    $endDate = normalizeDateString($data['end_date'] ?? $data['end'] ?? ($existingRow['end_date'] ?? ''), 'Target selesai');
    if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
        throw new InvalidArgumentException('Target selesai tidak boleh sebelum tanggal mulai');
    }

    $progress = (int)($data['progress'] ?? ($existingRow['progress'] ?? 0));
    if ($progress < 0 || $progress > 100) {
        throw new InvalidArgumentException('Progres project harus antara 0 hingga 100');
    }

    $budget = (float)($data['budget'] ?? ($existingRow['budget'] ?? 0));
    $actual = (float)($data['actual'] ?? ($existingRow['actual'] ?? 0));
    if ($budget < 0 || $actual < 0 || !is_finite($budget) || !is_finite($actual)) {
        throw new InvalidArgumentException('Nilai budget dan aktual tidak boleh negatif');
    }

    // Validasi ketat semua objek bersarang (id, enum, teks, angka, tanggal, tautan, referensi file) -> HTTP 400 bila tidak sah
    assertPlainProjectText($data, ['name', 'code', 'category', 'pic', 'area', 'vendor', 'po']);
    assertPlainProjectText($data, ['desc'], 'desc');
    assertPlainProjectText($data, ['description'], 'description');
    $metadata = validateProjectNested($metadata);
    if (array_key_exists('stages', $metadata)) {
        $metadata['stages'] = cleanNestedList($metadata['stages'], nestedProjectSpecs()['stages'], 'stages');
    }

    // Validasi dan sanitasi stages, milestones, quotations di dalam metadata
    if (isset($metadata['stages']) && is_array($metadata['stages'])) {
        $cleanStages = [];
        foreach ($metadata['stages'] as $stg) {
            if (!is_array($stg)) {
                continue;
            }
            $stgId = isset($stg['id']) && isValidEntityId((string)$stg['id']) ? (string)$stg['id'] : uuid4();
            $stgStatus = in_array((string)($stg['status'] ?? 'Not Started'), ['Not Started', 'On Progress', 'On Hold', 'Completed'], true)
                ? (string)$stg['status']
                : 'Not Started';
            $stg['id'] = $stgId;
            $stg['name'] = trim((string)($stg['name'] ?? ''));
            $stg['pic'] = trim((string)($stg['pic'] ?? ''));
            $stg['ps'] = normalizeDateString($stg['ps'] ?? '', 'Plan mulai stage');
            $stg['pf'] = normalizeDateString($stg['pf'] ?? '', 'Plan selesai stage');
            $stg['as'] = normalizeDateString($stg['as'] ?? '', 'Actual mulai stage');
            $stg['af'] = normalizeDateString($stg['af'] ?? '', 'Actual selesai stage');
            if ($stg['ps'] !== '' && $stg['pf'] !== '' && $stg['pf'] < $stg['ps']) {
                $stg['pf'] = $stg['ps'];
            }
            if ($stg['af'] !== '') {
                $stgStatus = 'Completed';
            }
            $stg['status'] = $stgStatus;
            $cleanStages[] = $stg;
        }
        $metadata['stages'] = $cleanStages;
    }

    $actorRole = (string)($actor['role'] ?? '');
    $actorName = trim((string)($actor['name'] ?? ($data['updated_by'] ?? $data['updatedBy'] ?? '')));
    $actorNameLower = strtolower($actorName);
    $existingPic = trim((string)($existingRow['pic'] ?? ''));
    $existingPicLower = strtolower($existingPic);
    $existingCreatedBy = is_array($existingMeta) ? strtolower(trim((string)($existingMeta['createdBy'] ?? ''))) : '';
    $isPITeamProject = $actorRole === 'user'
        && (trim((string)($existingMeta['improvementId'] ?? '')) !== ''
            || strcasecmp(trim((string)($existingRow['category'] ?? '')), 'Improvement') === 0);
    $claimsUnassignedProject = $existingRow !== null
        && $existingPicLower === ''
        && $actorNameLower !== ''
        && strtolower(trim((string)($data['pic'] ?? ''))) === $actorNameLower;
    $isOwnerOrAdmin = ($actorRole === 'admin')
        || $isPITeamProject
        || ($existingPicLower !== '' && $existingPicLower === $actorNameLower)
        || ($existingCreatedBy !== '' && $existingCreatedBy === $actorNameLower)
        || $claimsUnassignedProject;

    // P0-3: Only admin or current PIC may change PIC. An update that sets an empty pic is rejected with 400.
    if ($existingRow !== null) {
        if (array_key_exists('pic', $data) && trim((string)$data['pic']) === '') {
            throw new InvalidArgumentException('PIC project tidak boleh dikosongkan');
        }
        if (!$isOwnerOrAdmin && array_key_exists('pic', $data) && strtolower(trim((string)$data['pic'])) !== $existingPicLower) {
            throw new InvalidArgumentException('Hanya Admin atau PIC project saat ini yang dapat mengubah PIC');
        }
    }

    // P0-3 Stage-PIC rule: A stage PIC may change only their own stage's status/progress/note, never project metadata, pic, quotations or POs.
    if ($existingRow !== null && !$isOwnerOrAdmin) {
        $name = (string)$existingRow['name'];
        $status = (string)$existingRow['status'];
        $priority = (string)$existingRow['priority'];
        $pic = $existingPic;
        $startDate = normalizeDateString($existingRow['start_date'] ?? '', 'Tanggal mulai');
        $endDate = normalizeDateString($existingRow['end_date'] ?? '', 'Target selesai');
        $budget = (float)($existingRow['budget'] ?? 0);
        $actual = (float)($existingRow['actual'] ?? 0);
        $metadata['quotations'] = is_array($existingMeta['quotations'] ?? null) ? $existingMeta['quotations'] : [];
        $metadata['pos'] = is_array($existingMeta['pos'] ?? null) ? $existingMeta['pos'] : [];
        $metadata['milestones'] = is_array($existingMeta['milestones'] ?? null) ? $existingMeta['milestones'] : [];
        $metadata['docLinks'] = is_array($existingMeta['docLinks'] ?? null) ? $existingMeta['docLinks'] : [];

        $existingStages = is_array($existingMeta['stages'] ?? null) ? $existingMeta['stages'] : [];
        $stageMap = [];
        foreach ($existingStages as $stg) {
            if (is_array($stg) && isset($stg['id'])) {
                $stageMap[(string)$stg['id']] = $stg;
            }
        }
        $cleanStages = [];
        foreach (is_array($metadata['stages'] ?? null) ? $metadata['stages'] : [] as $stg) {
            $stgId = (string)($stg['id'] ?? '');
            $origStg = $stageMap[$stgId] ?? null;
            if ($origStg !== null && strtolower(trim((string)($origStg['pic'] ?? ''))) !== $actorNameLower) {
                $cleanStages[] = $origStg;
            } else {
                $cleanStages[] = $stg;
            }
        }
        $metadata['stages'] = $cleanStages;
    }

    if ($status === 'Completed') {
        $progress = 100;
    } elseif ($progress === 100 && $status === 'Not Started') {
        $status = 'Completed';
    }

    $incomingCreatedBy = trim((string)($metadata['createdBy'] ?? ($data['createdBy'] ?? '')));
    $createdBy = trim((string)($existingMeta['createdBy'] ?? ($incomingCreatedBy !== '' ? $incomingCreatedBy : ($existingRow['updated_by'] ?? ($existingRow['pic'] ?? $actorName)))));
    if ($createdBy !== '') {
        $metadata['createdBy'] = $createdBy;
    }

    $pic = trim((string)($data['pic'] ?? ($existingRow['pic'] ?? '')));
    if ($existingRow !== null && !$isOwnerOrAdmin) {
        $pic = $existingPic;
    } elseif ($existingRow === null && $pic === '' && $actorName !== '') {
        $pic = $actorName;
    }

    return [
        'id' => $rawId,
        'code' => substr(trim((string)($data['code'] ?? ($existingRow['code'] ?? ''))), 0, 80),
        'name' => $name,
        'category' => substr(trim((string)($data['category'] ?? ($existingRow['category'] ?? 'General'))) ?: 'General', 0, 100),
        'status' => $status,
        'priority' => $priority,
        'pic' => substr($pic, 0, 100),
        'area' => substr(trim((string)($data['area'] ?? ($existingRow['area'] ?? ''))), 0, 100),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'progress' => $progress,
        'description' => (string)($data['description'] ?? $data['desc'] ?? ($existingRow['description'] ?? '')),
        'vendor' => substr(trim((string)($data['vendor'] ?? ($existingRow['vendor'] ?? ''))), 0, 255),
        'budget' => round($budget, 2),
        'actual' => round($actual, 2),
        'po' => substr(trim((string)($data['po'] ?? ($existingRow['po'] ?? ''))), 0, 120),
        'metadata' => $metadata,
        'updated_by' => substr($actorName, 0, 100),
    ];
}

function canModifyProject(array $user, ?array $existingRow, ?array $incoming = null): bool
{
    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }
    if ($role !== 'user') {
        return false;
    }
    if ($existingRow === null) {
        return true;
    }

    $userName = strtolower(trim((string)($user['name'] ?? '')));
    if ($userName === '') {
        return false;
    }

    $pic = strtolower(trim((string)($existingRow['pic'] ?? '')));
    if ($pic !== '' && $pic === $userName) {
        return true;
    }
    if ($pic === '' && strtolower(trim((string)($incoming['pic'] ?? ''))) === $userName) {
        return true;
    }

    $meta = json_decode((string)($existingRow['metadata'] ?? '{}'), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    if (trim((string)($meta['improvementId'] ?? '')) !== ''
        || strcasecmp(trim((string)($existingRow['category'] ?? '')), 'Improvement') === 0) {
        return true;
    }

    $createdBy = strtolower(trim((string)($meta['createdBy'] ?? '')));
    if ($createdBy !== '' && $createdBy === $userName) {
        return true;
    }

    foreach (is_array($meta['stages'] ?? null) ? $meta['stages'] : [] as $stage) {
        if (is_array($stage) && strtolower(trim((string)($stage['pic'] ?? ''))) === $userName) {
            return true;
        }
    }

    return false;
}

function canDeleteProject(array $user, array $existingRow): bool
{
    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }
    if ($role !== 'user') {
        return false;
    }

    $userName = strtolower(trim((string)($user['name'] ?? '')));
    if ($userName === '') {
        return false;
    }

    $pic = strtolower(trim((string)($existingRow['pic'] ?? '')));
    if ($pic !== '' && $pic === $userName) {
        return true;
    }

    $meta = json_decode((string)($existingRow['metadata'] ?? '{}'), true);
    if ((is_array($meta) && trim((string)($meta['improvementId'] ?? '')) !== '')
        || strcasecmp(trim((string)($existingRow['category'] ?? '')), 'Improvement') === 0) {
        return true;
    }
    $createdBy = is_array($meta) ? strtolower(trim((string)($meta['createdBy'] ?? ''))) : '';
    if ($createdBy !== '' && $createdBy === $userName) {
        return true;
    }

    // P0-3: Only admin or the project owner. Empty-PIC project is admin only.
    return false;
}

function recordAuditLog(PDO $pdo, string $projectId, string $actor, string $action): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO project_logs (id, project_id, actor, action, created_at) VALUES (:id, :project_id, :actor, :action, NOW())'
    );
    $stmt->execute([
        ':id' => uuid4(),
        ':project_id' => substr($projectId ?: 'SYSTEM', 0, 36),
        ':actor' => substr($actor ?: 'system', 0, 100),
        ':action' => $action,
    ]);
}

function syncProjectChildren(PDO $pdo, array $project): void
{
    $projectId = (string)$project['id'];
    $meta = is_array($project['metadata'] ?? null) ? $project['metadata'] : [];

    // Sinkronisasi stages ke tabel project_stages
    $pdo->prepare('DELETE FROM project_stages WHERE project_id = :pid')->execute([':pid' => $projectId]);
    if (!empty($meta['stages']) && is_array($meta['stages'])) {
        $stgStmt = $pdo->prepare(
            'INSERT INTO project_stages (id, project_id, name, pic, start_date, end_date, status, note, created_at)
             VALUES (:id, :project_id, :name, :pic, :start_date, :end_date, :status, :note, NOW())'
        );
        foreach ($meta['stages'] as $stg) {
            if (!is_array($stg) || trim((string)($stg['name'] ?? '')) === '') {
                continue;
            }
            $ps = trim((string)($stg['ps'] ?? ''));
            $pf = trim((string)($stg['pf'] ?? ''));
            $stgStmt->execute([
                ':id' => uuid4(),
                ':project_id' => $projectId,
                ':name' => substr(trim((string)$stg['name']), 0, 160),
                ':pic' => substr(trim((string)($stg['pic'] ?? '')), 0, 100),
                ':start_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ps) ? $ps : null,
                ':end_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $pf) ? $pf : null,
                ':status' => substr(trim((string)($stg['status'] ?? 'Not Started')), 0, 50),
                ':note' => (string)($stg['note'] ?? ''),
            ]);
        }
    }

    // Sinkronisasi milestones ke tabel project_milestones
    $pdo->prepare('DELETE FROM project_milestones WHERE project_id = :pid')->execute([':pid' => $projectId]);
    if (!empty($meta['milestones']) && is_array($meta['milestones'])) {
        $msStmt = $pdo->prepare(
            'INSERT INTO project_milestones (id, project_id, name, due_date, status, note, created_at)
             VALUES (:id, :project_id, :name, :due_date, :status, :note, NOW())'
        );
        foreach ($meta['milestones'] as $ms) {
            if (!is_array($ms) || trim((string)($ms['name'] ?? '')) === '') {
                continue;
            }
            $due = trim((string)($ms['date'] ?? $ms['due_date'] ?? ''));
            $done = !empty($ms['done']) || strtolower((string)($ms['status'] ?? '')) === 'completed';
            $msStmt->execute([
                ':id' => uuid4(),
                ':project_id' => $projectId,
                ':name' => substr(trim((string)$ms['name']), 0, 160),
                ':due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
                ':status' => $done ? 'Completed' : 'Pending',
                ':note' => (string)($ms['note'] ?? ''),
            ]);
        }
    }

    // Sinkronisasi quotations ke tabel project_quotations
    $pdo->prepare('DELETE FROM project_quotations WHERE project_id = :pid')->execute([':pid' => $projectId]);
    if (!empty($meta['quotations']) && is_array($meta['quotations'])) {
        $qStmt = $pdo->prepare(
            'INSERT INTO project_quotations (id, project_id, vendor, amount, status, quotation_no, valid_until, note, created_at)
             VALUES (:id, :project_id, :vendor, :amount, :status, :quotation_no, :valid_until, :note, NOW())'
        );
        foreach ($meta['quotations'] as $q) {
            if (!is_array($q)) {
                continue;
            }
            $validUntil = trim((string)($q['valid'] ?? $q['valid_until'] ?? ''));
            $qStmt->execute([
                ':id' => uuid4(),
                ':project_id' => $projectId,
                ':vendor' => substr(trim((string)($q['vendor'] ?? '')), 0, 255),
                ':amount' => max(0.0, (float)($q['amount'] ?? 0)),
                ':status' => substr(trim((string)($q['status'] ?? 'Diterima')), 0, 50),
                ':quotation_no' => substr(trim((string)($q['no'] ?? $q['quotation_no'] ?? '')), 0, 120),
                ':valid_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil) ? $validUntil : null,
                ':note' => (string)($q['note'] ?? ''),
            ]);
        }
    }
}

function projectToOutput(array $row): array
{
    $metadata = json_decode((string)($row['metadata'] ?? '{}'), true);
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $updatedAt = isset($metadata['updatedAt'])
        ? (int)$metadata['updatedAt']
        : ($row['updated_at'] ? strtotime($row['updated_at']) * 1000 : 0);
    unset($metadata['metadata']);

    $status = (string)$row['status'];
    if (strcasecmp($status, 'In Progress') === 0) {
        $status = 'On Progress';
    }

    $project = [
        'id' => $row['id'],
        'code' => $row['code'],
        'name' => $row['name'],
        'category' => $row['category'],
        'status' => $status,
        'priority' => $row['priority'],
        'pic' => $row['pic'],
        'area' => $row['area'],
        'start' => $row['start_date'] ?? '',
        'end' => $row['end_date'] ?? '',
        'progress' => (int)$row['progress'],
        'desc' => $row['description'] ?? '',
        'vendor' => $row['vendor'],
        'budget' => (float)$row['budget'],
        'actual' => (float)$row['actual'],
        'po' => $row['po'],
        'updatedAt' => $updatedAt,
        'updatedBy' => (string)($metadata['updatedBy'] ?? $row['updated_by']),
        'createdBy' => (string)($metadata['createdBy'] ?? $row['pic'] ?: $row['updated_by']),
    ];

    return array_merge($metadata, $project);
}

ensureStorageDirs();
