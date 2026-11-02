<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$env = new TestEnv();
$env->resetSchema();
$env->seedUsers();

// Query MySQL / MariaDB engine version for the report
$dbVersion = (string)$env->pdo->query('SELECT VERSION()')->fetchColumn();
echo "  [INFO] Running against DB version: {$dbVersion}\n";

// Seed 20 synthetic user accounts for the 20 concurrent clients
$userCount = 20;
$stmt = $env->pdo->prepare('INSERT INTO users (id, name, password_hash, role, created_at) VALUES (:id, :name, :hash, :role, NOW()) ON DUPLICATE KEY UPDATE name = name');
$hash = password_hash('User1-Test-1', PASSWORD_DEFAULT);
for ($u = 1; $u <= $userCount; $u++) {
    $uname = sprintf('t_user_%02d', $u);
    $uid = sprintf('u-user-%02d', $u);
    $stmt->execute([':id' => $uid, ':name' => $uname, ':hash' => $hash, ':role' => 'user']);
}

// Start pool of 4 server instances to distribute concurrent HTTP requests
$baseUrls = $env->startServerPool(4);
$urlsArg = implode(',', $baseUrls);

$workerScript = __DIR__ . '/workers/sync_worker.php';

function runParallelWorkers(string $workerScript, string $urlsArg, string $mode, int $clients, int $iterations): array
{
    $procs = [];
    $pipes = [];
    $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pt_barrier_' . bin2hex(random_bytes(4));
    touch($barrier . '.wait');

    for ($c = 1; $c <= $clients; $c++) {
        $uname = sprintf('t_user_%02d', $c);
        $cmd = [
            PHP_BINARY,
            $workerScript,
            "--base-urls={$urlsArg}",
            "--user={$uname}",
            '--pin=User1-Test-1',
            "--mode={$mode}",
            "--iterations={$iterations}",
            "--client-id={$c}",
            "--barrier={$barrier}",
        ];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$c]);
        if (!is_resource($p)) {
            throw new RuntimeException("Failed to spawn worker {$c}");
        }
        fclose($pipes[$c][0]);
        $procs[$c] = $p;
    }

    // Wait until all workers have logged in and reached the barrier
    for ($c = 1; $c <= $clients; $c++) {
        $readyFile = $barrier . ".ready.{$c}";
        $waited = 0;
        while (!file_exists($readyFile) && $waited < 200) {
            usleep(20000);
            $waited++;
        }
        @unlink($readyFile);
    }

    // Release all workers simultaneously for a burst with no pauses
    @unlink($barrier . '.wait');

    $results = [];
    for ($c = 1; $c <= $clients; $c++) {
        $out = (string)stream_get_contents($pipes[$c][1]);
        $err = (string)stream_get_contents($pipes[$c][2]);
        fclose($pipes[$c][1]);
        fclose($pipes[$c][2]);
        proc_close($procs[$c]);

        if (trim($out) === '') {
            echo "  [WORKER EMPTY] Client {$c} stderr: {$err}\n";
        }

        $decoded = json_decode(trim($out), true);
        if (!is_array($decoded)) {
            $results[$c] = [
                'clientId' => $c,
                'raw' => $out,
                'stderr' => $err,
                'statuses' => [],
                'errors' => ["Failed to parse JSON: {$out} {$err}"],
            ];
        } else {
            $results[$c] = $decoded;
            if (!empty($decoded['fatal']) || !empty($decoded['errors'])) {
                echo "  [WORKER-ERR] Client {$c}: " . json_encode($decoded) . "\n";
            }
        }
    }

    return $results;
}

try {
    // ------------------------------------------------------------------------
    // Static & Code Contracts for P0-2
    // ------------------------------------------------------------------------
    $apiSrc = (string)file_get_contents($env->rootDir . '/api.php');
    $schemaSrc = (string)file_get_contents($env->rootDir . '/schema.sql');
    $appJsSrc = (string)file_get_contents($env->rootDir . '/public/app.js');
    $migrationExists = file_exists($env->rootDir . '/migrations/001_seed_sync_settings.sql');

    T::check($migrationExists, 'Migration migrations/001_seed_sync_settings.sql exists');
    T::check(str_contains($schemaSrc, "'tracker'") && str_contains($schemaSrc, "'tracker_state'"), 'schema.sql pre-populates tracker and tracker_state in settings table');
    T::check(!str_contains($apiSrc, 'INSERT IGNORE INTO settings'), 'api.php readJsonSettingForUpdate does not use INSERT IGNORE inside request');
    T::check(str_contains($appJsSrc, 'Math.random()') && str_contains($appJsSrc, '2000'), 'public/app.js adds 0-2s random jitter to SSE event handler before syncing');

    // Test router catches PDOException and returns 503 + Retry-After: 1
    // We can simulate an unhandled DB exception endpoint or verify the exception handler
    T::check(str_contains($apiSrc, '503') && (str_contains($apiSrc, 'Retry-After') || str_contains($apiSrc, 'retry_after')), 'api.php router returns 503 + Retry-After on PDOException');

    // ------------------------------------------------------------------------
    // Part (a): 20 parallel clients x 15 iterations of polling only
    // ------------------------------------------------------------------------
    $pollResults = runParallelWorkers($workerScript, $urlsArg, 'poll', 20, 15);
    $poll500Count = 0;
    $pollTotalRequests = 0;
    foreach ($pollResults as $res) {
        foreach ($res['statuses'] ?? [] as $st) {
            $pollTotalRequests++;
            if ($st === 500) {
                $poll500Count++;
            }
        }
    }
    T::check($pollTotalRequests === 300, "Part (a) Polling: all 300 requests executed", ['total' => $pollTotalRequests]);
    T::check($poll500Count === 0, "Part (a) Polling: 0 HTTP 500 across 20 clients x 15 iterations", ['500_count' => $poll500Count]);

    // ------------------------------------------------------------------------
    // Part (b): 20 parallel clients x 15 iterations of saving projects
    // ------------------------------------------------------------------------
    $saveResults = runParallelWorkers($workerScript, $urlsArg, 'save', 20, 15);
    $save500Count = 0;
    $saveTotalRequests = 0;
    foreach ($saveResults as $res) {
        foreach ($res['statuses'] ?? [] as $st) {
            $saveTotalRequests++;
            if ($st === 500) {
                $save500Count++;
            }
        }
    }
    T::check($saveTotalRequests >= 300, "Part (b) Saving: all requests executed (>= 300)", ['total' => $saveTotalRequests]);
    T::check($save500Count === 0, "Part (b) Saving: 0 HTTP 500 across 20 clients x 15 iterations", ['500_count' => $save500Count]);

    // Verify no lost update: All 20 projects exist and have progress = 75 (15 * 5)
    $stmt = $env->pdo->query('SELECT id, progress, updated_by FROM projects ORDER BY id ASC');
    $savedProjects = $stmt->fetchAll();
    T::check(count($savedProjects) === 20, 'Part (b) Saving: exactly 20 distinct projects exist in DB', ['count' => count($savedProjects)]);

    $allFinalProgressMatch = true;
    $mismatches = [];
    foreach ($savedProjects as $row) {
        if ((int)$row['progress'] !== 75) {
            $allFinalProgressMatch = false;
            $mismatches[$row['id']] = $row['progress'];
        }
    }
    T::check($allFinalProgressMatch, 'Part (b) Saving: no lost updates, all 20 projects reached final progress 75%', $mismatches);

} finally {
    $env->stopServer();
}
