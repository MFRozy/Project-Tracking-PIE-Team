<?php

declare(strict_types=1);

/**
 * Regression suite runner.
 *   php tests/regression/run.php            run every test_*.php
 *   php tests/regression/run.php filter     only files whose name contains "filter"
 * DB settings come from PT_TEST_DB_* (see bootstrap.php / setup-test-db.sql).
 */

require_once __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$env = null;
$exit = 1;

try {
    $env = new TestEnv();
    $env->resetSchema();
    $users = $env->seedUsers();
    $env->startServer();

    $files = glob(__DIR__ . '/test_*.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        if ($filter !== '' && !str_contains(basename($file), $filter)) {
            continue;
        }
        echo '=== ' . basename($file) . " ===\n";
        // Each test file receives $env (TestEnv) and $users (name => id).
        (static function (TestEnv $env, array $users) use ($file): void {
            require $file;
        })($env, $users);
    }

    echo sprintf("\nPassed: %d  Failed: %d  Skipped: %d\n", T::$passed, T::$failed, T::$skipped);
    $exit = T::$failed === 0 ? 0 : 1;
} catch (Throwable $e) {
    fwrite(STDERR, 'HARNESS ERROR: ' . $e->getMessage() . "\n");
    $exit = 2;
} finally {
    if ($env instanceof TestEnv) {
        $env->stopServer();
    }
}
exit($exit);

