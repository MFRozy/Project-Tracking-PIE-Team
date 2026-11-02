<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$rootDir = dirname(__DIR__, 2);

// ----------------------------------------------------------------------------
// Test 0: Static structure checks
// ----------------------------------------------------------------------------
T::check(!is_file($rootDir . '/index.php'), 'index.php is moved out of repository root');
T::check(is_file($rootDir . '/public/index.php'), 'public/index.php exists as entry point');
T::check(is_file($rootDir . '/public/.htaccess'), 'public/.htaccess exists');

$readme = (string)file_get_contents($rootDir . '/README.md');
$panduan = (string)file_get_contents($rootDir . '/PANDUAN.txt');
T::check(str_contains($readme, 'DocumentRoot') && str_contains($readme, 'public'), 'README.md documents DocumentRoot pointing to public');
T::check(str_contains($panduan, 'DocumentRoot') && str_contains($panduan, 'public'), 'PANDUAN.txt documents DocumentRoot pointing to public');

// ----------------------------------------------------------------------------
// Test 1: Acceptance 1 - DocumentRoot pointing at repo root (legacy)
// ----------------------------------------------------------------------------
$envLegacy = new TestEnv();
$envLegacy->resetSchema();
$envLegacy->seedUsers();

// Start php -S with legacy root and legacy_router.php
$legacyPort = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock !== false) {
    $legacyPort = (int)explode(':', (string)stream_socket_get_name($sock, false))[1];
    fclose($sock);
}

$legacyUrl = "http://127.0.0.1:{$legacyPort}";
$nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$legacyEnv = [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => $envLegacy->dbName,
    'DB_USER' => 'pt_test',
    'DB_PASS' => 'pt_test_local_only',
    'PT_SESSION_SECRET' => bin2hex(random_bytes(32)),
    'PT_STORAGE_DIR' => sys_get_temp_dir() . '/pt_docroot_legacy_' . bin2hex(random_bytes(4)),
    'PT_PIN_MIN_LENGTH' => '10',
    'PT_SESSION_HOURS' => '12',
    'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
    'PATH' => getenv('PATH') ?: '',
    'TEMP' => sys_get_temp_dir(),
    'TMP' => sys_get_temp_dir(),
];
mkdir($legacyEnv['PT_STORAGE_DIR'], 0750, true);

$legacyRouter = __DIR__ . '/legacy_router.php';
$cmdLegacy = [PHP_BINARY, '-S', "127.0.0.1:{$legacyPort}", '-t', $rootDir, $legacyRouter];
$procLegacy = proc_open($cmdLegacy, [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipesLegacy, $rootDir, $legacyEnv);

try {
    // Wait for server ready
    $probe = new HttpClient($legacyUrl);
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(100000);
        $r = $probe->send('GET', $legacyUrl . '/api/health');
        $json = json_decode($r['raw'], true);
        if ($r['status'] === 200 && ($json['ok'] ?? false) === true) {
            $ready = true;
            break;
        }
    }
    T::check($ready, 'Legacy server started and ready');

    // 1. Sensitive files & directories must be blocked (403 or 404)
    $blockedPaths = [
        '/.env',
        '/config.php',
        '/db.php',
        '/schema.sql',
        '/api.php',
        '/storage/',
        '/tests/',
        '/tests/test-security-php.php',
    ];

    foreach ($blockedPaths as $path) {
        $res = $probe->send('GET', $legacyUrl . $path);
        T::check(in_array($res['status'], [403, 404], true), "Legacy root blocks {$path} (HTTP 403/404)", $res['status']);
    }

    // 2. Legitimate paths must work
    $resRoot = $probe->send('GET', $legacyUrl . '/');
    T::check($resRoot['status'] === 200 && str_contains($resRoot['raw'], 'PI Project Management'), 'Legacy root: / serves UI (200)');

    $resSvg = $probe->send('GET', $legacyUrl . '/public/cosmax-mark.svg');
    T::check($resSvg['status'] === 200, 'Legacy root: /public/cosmax-mark.svg serves static file (200)');

    $resApi = $probe->send('GET', $legacyUrl . '/api/health');
    $apiJson = json_decode($resApi['raw'], true);
    T::check($resApi['status'] === 200 && ($apiJson['ok'] ?? false) === true, 'Legacy root: /api/health works (200)');

} finally {
    if (is_resource($procLegacy)) {
        proc_terminate($procLegacy);
        proc_close($procLegacy);
    }
}

// ----------------------------------------------------------------------------
// Test 2: Acceptance 2 - DocumentRoot pointing at public/ (target)
// ----------------------------------------------------------------------------
$targetPort = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock !== false) {
    $targetPort = (int)explode(':', (string)stream_socket_get_name($sock, false))[1];
    fclose($sock);
}

$targetUrl = "http://127.0.0.1:{$targetPort}";
$targetEnv = [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => $envLegacy->dbName,
    'DB_USER' => 'pt_test',
    'DB_PASS' => 'pt_test_local_only',
    'PT_SESSION_SECRET' => bin2hex(random_bytes(32)),
    'PT_STORAGE_DIR' => sys_get_temp_dir() . '/pt_docroot_target_' . bin2hex(random_bytes(4)),
    'PT_PIN_MIN_LENGTH' => '10',
    'PT_SESSION_HOURS' => '12',
    'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
    'PATH' => getenv('PATH') ?: '',
    'TEMP' => sys_get_temp_dir(),
    'TMP' => sys_get_temp_dir(),
];
mkdir($targetEnv['PT_STORAGE_DIR'], 0750, true);

$publicDir = $rootDir . '/public';
$cmdTarget = [PHP_BINARY, '-S', "127.0.0.1:{$targetPort}", '-t', $publicDir, $publicDir . '/index.php'];
$procTarget = proc_open($cmdTarget, [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipesTarget, $publicDir, $targetEnv);

try {
    // Wait for server ready
    $probeTarget = new HttpClient($targetUrl);
    $readyTarget = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(100000);
        $r = $probeTarget->request('GET', '/api/health');
        if ($r['status'] === 200 && ($r['json']['ok'] ?? false) === true) {
            $readyTarget = true;
            break;
        }
    }
    T::check($readyTarget, 'Target server (DocRoot=public) started and ready');

    // 1. API route works
    $resApi = $probeTarget->request('GET', '/api/health');
    T::check($resApi['status'] === 200 && ($resApi['json']['ok'] ?? false) === true, 'Target DocRoot: /api/health works (200)');

    // 2. Static files inside public/ work
    $resShared = $probeTarget->send('GET', $targetUrl . '/shared.js');
    T::check($resShared['status'] === 200 && str_contains($resShared['raw'], 'normP'), 'Target DocRoot: /shared.js works (200)');

    // 3. UI index works
    $resUi = $probeTarget->send('GET', $targetUrl . '/');
    T::check($resUi['status'] === 200 && str_contains($resUi['raw'], 'PI Project Management'), 'Target DocRoot: / serves UI (200)');

    // 4. Traversal requests outside public/ must fail (400 or 404)
    $traversalPaths = [
        '/../.env',
        '/../config.php',
        '/../db.php',
        '/../schema.sql',
        '/.env',
        '/config.php',
        '/db.php',
        '/schema.sql',
    ];

    foreach ($traversalPaths as $path) {
        $res = $probeTarget->send('GET', $targetUrl . $path);
        T::check(in_array($res['status'], [400, 403, 404], true), "Target DocRoot blocks outside path {$path} (HTTP 400/403/404)", $res['status']);
    }

} finally {
    if (is_resource($procTarget)) {
        proc_terminate($procTarget);
        proc_close($procTarget);
    }
}
