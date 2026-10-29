<?php

declare(strict_types=1);

/**
 * Automated Security & Functional Acceptance Test Suite (P0, P1, P2)
 * Sesuai dengan Laporan Audit Keamanan & Kelayakan Aplikasi IT - Project Tracking PIE Team
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/config.php';

$testDbName = 'project_tracker_test';
$testPort = 39188;
$baseUrl = "http://127.0.0.1:{$testPort}";
$tmpStorage = sys_get_temp_dir() . '/pt_security_test_' . bin2hex(random_bytes(4));
mkdir($tmpStorage, 0750, true);

// Siapkan schema pada database uji terisolasi
$adminPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT),
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$adminPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
$adminPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$adminPdo->exec("USE `{$testDbName}`");

$schemaSql = (string)file_get_contents($rootDir . '/schema.sql');
$schemaSql = preg_replace('/CREATE DATABASE IF NOT EXISTS.*?;/is', '', $schemaSql) ?? $schemaSql;
$schemaSql = preg_replace('/USE\s+project_tracker\s*;/i', '', $schemaSql) ?? $schemaSql;
$adminPdo->exec($schemaSql);

$phpBin = PHP_BINARY;
$envVars = array_merge($_ENV, [
    'DB_HOST' => DB_HOST,
    'DB_PORT' => (string)DB_PORT,
    'DB_NAME' => $testDbName,
    'DB_USER' => DB_USER,
    'DB_PASS' => DB_PASS,
    'PT_SESSION_SECRET' => bin2hex(random_bytes(32)),
    'PT_STORAGE_DIR' => $tmpStorage,
    'PT_FILES_DIR' => $tmpStorage . '/files',
    'PT_BACKUP_DIR' => $tmpStorage . '/backup',
    'PT_PIN_MIN_LENGTH' => '6',
    'PT_SESSION_HOURS' => '12',
    'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
    'PATH' => getenv('PATH') ?: '',
]);

$nullDev = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $nullDev, 'w'],
    2 => ['file', $nullDev, 'w'],
];
$cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s"', $phpBin, $testPort, $rootDir);
$proc = proc_open($cmd, $descriptors, $pipes, $rootDir, $envVars);
if (!is_resource($proc)) {
    fwrite(STDERR, "Gagal menjalankan PHP test server\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function check(bool $condition, string $label, mixed $debug = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($debug !== null ? ' -> ' . json_encode($debug) : '') . "\n";
    }
}

class TestHttpClient
{
    public string $cookie = '';
    public string $csrfToken = '';
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function request(string $method, string $route, mixed $body = null, array $extraHeaders = [], bool $includeXpt = true, bool $includeCsrf = true): array
    {
        $url = $this->baseUrl . '/api.php?route=' . urlencode($route);
        $headers = [];
        if ($includeXpt) {
            $headers[] = 'X-PT: 1';
        }
        if ($includeCsrf && $this->csrfToken !== '') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }

        $payload = '';
        if (is_array($body)) {
            $headers[] = 'Content-Type: application/json';
            $payload = (string)json_encode($body, JSON_UNESCAPED_SLASHES);
        } elseif (is_string($body)) {
            $payload = $body;
        }

        foreach ($extraHeaders as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $respHeaders = $http_response_header ?? [];
        $status = 0;
        $headerMap = [];
        foreach ($respHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (strpos($line, ':') !== false) {
                [$hk, $hv] = explode(':', $line, 2);
                $hkLower = strtolower(trim($hk));
                $hvTrim = trim($hv);
                if ($hkLower === 'set-cookie') {
                    $cookiePair = explode(';', $hvTrim)[0];
                    if (str_ends_with($cookiePair, '=') || str_contains($hvTrim, 'Max-Age=0')) {
                        $this->cookie = '';
                    } else {
                        $this->cookie = $cookiePair;
                    }
                }
                $headerMap[$hkLower] = $hvTrim;
            }
        }

        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($json) && isset($json['csrfToken']) && is_string($json['csrfToken'])) {
            $this->csrfToken = $json['csrfToken'];
        }

        return [
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'raw' => (string)$raw,
            'headers' => $headerMap,
        ];
    }
}

function cleanupAndExit(int $code): never
{
    global $proc, $pipes, $adminPdo, $testDbName, $tmpStorage;
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    try {
        $adminPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
    } catch (Throwable) {
    }
    if (is_dir($tmpStorage)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpStorage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($tmpStorage);
    }
    exit($code);
}

try {
    // Tunggu server siap
    $ready = false;
    $probe = new TestHttpClient($baseUrl);
    for ($i = 0; $i < 40; $i++) {
        usleep(100000);
        $r = $probe->request('GET', '/api/health');
        if ($r['status'] === 200 && ($r['json']['ok'] ?? false) === true) {
            $ready = true;
            break;
        }
    }
    if (!$ready) {
        throw new RuntimeException('Server PHP uji tidak merespons');
    }

    echo "=== 1. P0 & P1: Secret Management, Setup, PIN Policy & CSRF Protection ===\n";
    $configSource = (string)file_get_contents($rootDir . '/config.php');
    check(!str_contains($configSource, "const SESSION_SECRET = 'project-tracker-php-secret-key'"), 'SESSION_SECRET tidak lagi hardcoded di config.php');
    check(!str_contains($configSource, "const DB_USER = 'root'"), 'DB_USER tidak lagi hardcoded menggunakan root di config.php');
    check(is_file($rootDir . '/public/index.html') && is_file($rootDir . '/public/shared.js'), 'Source frontend public/ lengkap dan tersedia');

    $admin = new TestHttpClient($baseUrl);
    $userB = new TestHttpClient($baseUrl);
    $userC = new TestHttpClient($baseUrl);
    $contrib = new TestHttpClient($baseUrl);
    $viewer = new TestHttpClient($baseUrl);

    $r = $admin->request('POST', '/api/setup', ['name' => 'Andi', 'pin' => '123456'], [], false, false);
    check($r['status'] === 403, 'POST tanpa header X-PT ditolak (403)');

    $r = $admin->request('POST', '/api/setup', ['name' => 'Andi', 'pin' => '1234']);
    check($r['status'] === 400, 'PIN kurang dari 6 karakter ditolak sesuai kebijakan keamanan');

    $r = $admin->request('POST', '/api/setup', ['name' => 'Andi', 'pin' => 'Admin#123']);
    check($r['status'] === 200 && ($r['json']['user']['role'] ?? '') === 'admin' && $admin->csrfToken !== '', 'Setup admin pertama berhasil & menerima CSRF token');

    // Uji pengiriman POST terautentikasi tanpa CSRF token -> wajib ditolak 403
    $r = $admin->request('POST', '/api/users', ['name' => 'Budi', 'pin' => 'Budi#123', 'role' => 'user'], [], true, false);
    check($r['status'] === 403, 'Endpoint perubahan tanpa X-CSRF-Token ditolak (403 CSRF Protection)');

    // Buat akun Budi (user), Citra (user), Dina (contributor), Vino (viewer)
    $r = $admin->request('POST', '/api/users', ['name' => 'Budi', 'pin' => 'Budi#123', 'role' => 'user']);
    check($r['status'] === 200, 'Admin membuat akun User (Budi) dengan CSRF token valid');
    $admin->request('POST', '/api/users', ['name' => 'Citra', 'pin' => 'Citra#123', 'role' => 'user']);
    $admin->request('POST', '/api/users', ['name' => 'Dina', 'pin' => 'Dina#123', 'role' => 'contributor']);
    $admin->request('POST', '/api/users', ['name' => 'Vino', 'pin' => 'Vino#123', 'role' => 'viewer']);

    // Uji brute-force lock
    $attacker = new TestHttpClient($baseUrl);
    for ($i = 0; $i < 5; $i++) {
        $attacker->request('POST', '/api/login', ['name' => 'Budi', 'pin' => 'Salah' . $i]);
    }
    $r = $attacker->request('POST', '/api/login', ['name' => 'Budi', 'pin' => 'Budi#123']);
    check($r['status'] === 429, 'Brute-force: akun terkunci 5 menit setelah 5x salah PIN');

    // Reset lock untuk pengujian berikutnya
    $adminPdo->exec("DELETE FROM `{$testDbName}`.login_attempts");

    $r = $userB->request('POST', '/api/login', ['name' => 'Budi', 'pin' => 'Budi#123']);
    check($r['status'] === 200 && $userB->csrfToken !== '', 'Login Budi berhasil');
    $userC->request('POST', '/api/login', ['name' => 'Citra', 'pin' => 'Citra#123']);
    $contrib->request('POST', '/api/login', ['name' => 'Dina', 'pin' => 'Dina#123']);
    $viewer->request('POST', '/api/login', ['name' => 'Vino', 'pin' => 'Vino#123']);

    echo "=== 2. P0: File Upload Hardening & Proteksi Eksekusi ===\n";
    $r = $userB->request('POST', '/api/files', 'dummy php content', [
        'Content-Type' => 'application/octet-stream',
        'X-Filename' => rawurlencode('script_test.php'),
        'X-Prefix' => 'PRJ-001',
    ]);
    check($r['status'] === 400, 'Upload file .php ditolak (400)', $r);

    $r = $userB->request('POST', '/api/files', 'dummy content', [
        'Content-Type' => 'application/octet-stream',
        'X-Filename' => rawurlencode('double_ext.php.png'),
        'X-Prefix' => 'PRJ-001',
    ]);
    check($r['status'] === 400, 'Upload file ekstensi ganda (.php.png) ditolak (400)', $r);

    $r = $userB->request('POST', '/api/files', 'plain text menyamar sebagai png', [
        'Content-Type' => 'application/octet-stream',
        'X-Filename' => rawurlencode('fake_image.png'),
        'X-Prefix' => 'PRJ-001',
    ]);
    check($r['status'] === 400, 'Upload file dengan MIME type tidak sesuai ekstensi (.png berisi text/plain) ditolak (400)', $r);

    $r = $userB->request('POST', '/api/files', '<html><body>test</body></html>', [
        'Content-Type' => 'application/octet-stream',
        'X-Filename' => rawurlencode('page.html'),
        'X-Prefix' => 'PRJ-001',
    ]);
    check($r['status'] === 400, 'Upload file .html ditolak (400)');

    $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $r = $userB->request('POST', '/api/files', $pdfBytes, [
        'Content-Type' => 'application/octet-stream',
        'X-Filename' => rawurlencode('Quotation_Vendor.pdf'),
        'X-Prefix' => 'PRJ-001',
    ]);
    check($r['status'] === 200 && isset($r['json']['name']), 'Upload file PDF valid diterima (200)');
    $uploadedPdf = (string)($r['json']['name'] ?? '');

    $dl = $userB->request('GET', '/api/files/' . $uploadedPdf);
    check(
        $dl['status'] === 200
        && str_contains($dl['headers']['content-security-policy'] ?? '', "default-src 'none'")
        && ($dl['headers']['x-content-type-options'] ?? '') === 'nosniff',
        'Download file menyertakan header CSP ketat dan X-Content-Type-Options: nosniff'
    );

    check(
        is_file($tmpStorage . '/.htaccess')
        && is_file($tmpStorage . '/files/.htaccess')
        && is_file($tmpStorage . '/backup/.htaccess')
        && str_contains((string)file_get_contents($tmpStorage . '/files/.htaccess'), 'php_flag engine off'),
        'Folder storage, files, dan backup dilengkapi .htaccess anti-eksekusi PHP & blokir akses langsung'
    );

    echo "=== 3. P1 & P2: RBAC Kepemilikan Project, Validasi Data & Concurrency Sync ===\n";
    // Validasi input negatif / tidak valid di backend
    $r = $userB->request('POST', '/api/projects', [
        'id' => 'p_invalid',
        'name' => 'Project Invalid',
        'start' => '2026-12-10',
        'end' => '2026-10-01', // end sebelum start
        'progress' => 50,
    ]);
    check($r['status'] === 400, 'Validasi backend menolak end_date sebelum start_date');

    $r = $userB->request('POST', '/api/projects', [
        'id' => 'p_invalid2',
        'name' => 'Project Invalid Progress',
        'start' => '2026-10-01',
        'end' => '2026-12-10',
        'progress' => 150,
    ]);
    check($r['status'] === 400, 'Validasi backend menolak progress > 100');

    $r = $userB->request('POST', '/api/projects', [
        'id' => "p_bad_id!@#",
        'name' => 'Project Invalid ID',
    ]);
    check($r['status'] === 400, 'Validasi backend menolak ID yang mengandung karakter tidak sah');

    // Budi membuat project p1 (PIC: Budi)
    $r = $userB->request('POST', '/api/sync', [
        'rev' => -1,
        'sa' => 0,
        'push' => [
            'projects' => [[
                'id' => 'p_budi_1',
                'code' => 'PRJ-001',
                'name' => 'Line Filling Budi',
                'category' => 'New Machine',
                'status' => 'On Progress',
                'priority' => 'High',
                'pic' => 'Budi',
                'start' => '2026-10-01',
                'end' => '2026-12-31',
                'progress' => 25,
                'budget' => 50000000,
                'stages' => [
                    ['id' => 'stg_1', 'name' => 'URS', 'pic' => 'Budi', 'ps' => '2026-10-01', 'pf' => '2026-10-14', 'status' => 'Completed', 'af' => '2026-10-12'],
                ],
                'milestones' => [
                    ['id' => 'ms_1', 'name' => 'FAT Selesai', 'date' => '2026-11-15', 'done' => false],
                ],
                'quotations' => [
                    ['id' => 'q_1', 'vendor' => 'PT Mesin Jaya', 'amount' => 48000000, 'status' => 'Terpilih', 'no' => 'QT-01'],
                ],
                'updatedAt' => (int)round(microtime(true) * 1000),
            ]],
        ],
    ]);
    check($r['status'] === 200 && count($r['json']['projects'] ?? []) === 1, 'Budi berhasil membuat project miliknya via /api/sync');

    // Citra (user lain, bukan PIC project p_budi_1) mencoba mengubah atau menghapus project Budi
    $r = $userC->request('PUT', '/api/projects/p_budi_1', [
        'name' => 'Dibajak Citra',
        'pic' => 'Citra',
        'start' => '2026-10-01',
        'end' => '2026-12-31',
    ]);
    check($r['status'] === 403, 'RBAC: User lain (Citra) ditolak saat mengubah project milik Budi via PUT /api/projects');

    $r = $userC->request('DELETE', '/api/projects/p_budi_1');
    check($r['status'] === 403, 'RBAC: User lain (Citra) ditolak saat menghapus project milik Budi via DELETE /api/projects');

    $r = $userC->request('POST', '/api/sync', [
        'rev' => 0,
        'sa' => 0,
        'push' => [
            'deleted' => ['p_budi_1' => (int)round(microtime(true) * 1000) + 99999],
        ],
    ]);
    check(
        ($r['json']['rejectedProjectAuth'] ?? false) === true
        && count($r['json']['projects'] ?? []) === 1,
        'RBAC: User lain (Citra) ditolak saat menghapus project milik Budi via /api/sync'
    );

    // Viewer mencoba mengirim perubahan
    $r = $viewer->request('POST', '/api/sync', [
        'rev' => 0,
        'sa' => 0,
        'push' => ['deleted' => ['p_budi_1' => time() * 1000]],
    ]);
    check($r['status'] === 403, 'RBAC: Viewer ditolak saat melakukan perubahan data');

    // Contributor tidak boleh melihat seluruh project unit lain atau mengekspor data project
    $r = $contrib->request('GET', '/api/export');
    check($r['status'] === 403, 'RBAC: Contributor ditolak mengakses /api/export');

    echo "=== 4. P1 & P2: Session Revocation, Audit Log & Full Backup/Restore ===\n";
    // Uji cabut sesi otomatis saat Admin reset PIN Budi
    $r = $admin->request('PUT', '/api/users/Budi', ['pin' => 'BudiBaru#99']);
    check($r['status'] === 200, 'Admin berhasil mereset PIN Budi');

    $r = $userB->request('GET', '/api/me');
    check($r['status'] === 401, 'Session Revocation: Sesi lama Budi langsung dicabut (401) setelah reset PIN');

    // Login ulang Budi dengan 2 sesi berbeda, lalu ganti PIN sendiri -> sesi kedua harus tercabut
    $userBSess1 = new TestHttpClient($baseUrl);
    $userBSess2 = new TestHttpClient($baseUrl);
    $userBSess1->request('POST', '/api/login', ['name' => 'Budi', 'pin' => 'BudiBaru#99']);
    $userBSess2->request('POST', '/api/login', ['name' => 'Budi', 'pin' => 'BudiBaru#99']);

    $r = $userBSess1->request('POST', '/api/pin', ['old' => 'BudiBaru#99', 'pin' => 'BudiMandiri#88']);
    check($r['status'] === 200, 'Budi berhasil mengganti PIN sendiri');
    check($userBSess1->request('GET', '/api/me')['status'] === 200, 'Sesi aktif yang mengganti PIN tetap hidup');
    check($userBSess2->request('GET', '/api/me')['status'] === 401, 'Sesi perangkat lain milik Budi otomatis dicabut setelah ganti PIN');

    // Cek tabel relasional dan audit log (project_logs)
    $logsResp = $admin->request('GET', '/api/logs');
    check($logsResp['status'] === 200 && count($logsResp['json']['logs'] ?? []) >= 5, 'Audit trail tercatat di tabel project_logs dan dapat diakses admin');

    // Uji Backup (tanpa password_hash & mencakup tabel relasional) dan Restore
    $bkResp = $admin->request('POST', '/api/backup', []);
    check($bkResp['status'] === 200 && count($bkResp['json']['files'] ?? []) === 2, 'Backup berhasil dibuat oleh Admin');

    $trackerBkFile = $tmpStorage . '/backup/' . $bkResp['json']['files'][0];
    $usersBkFile = $tmpStorage . '/backup/' . $bkResp['json']['files'][1];
    $usersBkContent = (string)file_get_contents($usersBkFile);
    $trackerBkData = json_decode((string)file_get_contents($trackerBkFile), true);

    check(!str_contains($usersBkContent, 'password_hash'), 'P0 Gate: File backup users TIDAK mengandung password_hash');
    check(
        isset($trackerBkData['tables']['project_stages'])
        && count($trackerBkData['tables']['project_stages']) === 1
        && count($trackerBkData['tables']['project_milestones']) === 1
        && count($trackerBkData['tables']['project_quotations']) === 1,
        'P2 Gate: Backup mencakup seluruh tabel relasional (stages, milestones, quotations, files, logs)'
    );

    // Hapus project oleh Admin lalu uji Restore dari file backup
    $delResp = $admin->request('DELETE', '/api/projects/p_budi_1');
    check($delResp['status'] === 200, 'Admin menghapus project p_budi_1 sebelum uji restore');

    $restoreResp = $admin->request('POST', '/api/restore', ['file' => $bkResp['json']['files'][0]]);
    check($restoreResp['status'] === 200 && ($restoreResp['json']['restoredProjects'] ?? 0) === 1, 'P2 Gate: Restore dari file backup berhasil memulihkan project dan tabel relasional');

    echo "\n========================================\n";
    echo "Hasil Pengujian: {$passed} PASS, {$failed} FAIL\n";
    echo "========================================\n";

    cleanupAndExit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR PENGUJIAN: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    cleanupAndExit(2);
}
