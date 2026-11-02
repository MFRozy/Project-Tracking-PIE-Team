<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpClient.php';

// Ensure $_ENV mirrors getenv() even if variables_order omits 'E'
foreach (getenv() as $k => $v) {
    if (!isset($_ENV[$k])) {
        $_ENV[$k] = (string)$v;
    }
}
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'test-security-php.php') {
    $_ENV['PT_ENABLE_APP_BACKUP'] = 'true';
}

/**
 * Shared test environment: safe DB connection, schema/migration load, PHP built-in server,
 * synthetic users. Reads ONLY PT_TEST_DB_* variables, never the app's real .env credentials.
 */
final class TestEnv
{
    public const SYNTHETIC_USERS = [
        // name => [role, pin]  (synthetic, test DB only)
        't_admin' => ['admin', 'Adm!n-Test-1'],
        't_user1' => ['user', 'User1-Test-1'],
        't_user2' => ['user', 'User2-Test-1'],
        't_contrib' => ['contributor', 'Contrib-Test-1'],
        't_viewer' => ['viewer', 'Viewer-Test-1'],
    ];

    public string $rootDir;
    public string $baseUrl = '';
    /** @var list<string> */
    public array $baseUrls = [];
    public string $storageDir = '';
    public string $dbName;
    public PDO $pdo;
    /** @var resource|null */
    private $proc = null;
    /** @var list<resource> */
    private array $procs = [];

    /** @var array<string,string> */
    private array $cfg;

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__, 2);
        $this->cfg = [
            'host' => getenv('PT_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('PT_TEST_DB_PORT') ?: '3306',
            'name' => getenv('PT_TEST_DB_NAME') ?: 'project_tracker_test',
            'user' => getenv('PT_TEST_DB_USER') ?: 'pt_test',
            'pass' => getenv('PT_TEST_DB_PASS') !== false ? (string)getenv('PT_TEST_DB_PASS') : 'pt_test_local_only',
        ];
        $this->dbName = $this->cfg['name'];

        // Hard safety guards: never operate on anything that is not clearly a throwaway test DB.
        if (!preg_match('/^[A-Za-z0-9_]+_test$/', $this->dbName)) {
            throw new RuntimeException("Refusing to run: database '{$this->dbName}' does not end in _test");
        }
        if (strtolower($this->cfg['user']) === 'root') {
            throw new RuntimeException('Refusing to run as DB user root; use the least-privilege pt_test user');
        }
        if (!in_array($this->cfg['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Refusing to run against a non-local DB host');
        }

        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->cfg['host'], (int)$this->cfg['port'], $this->dbName),
            $this->cfg['user'],
            $this->cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    /** Drop every table inside the test DB (never the database itself), then load schema + migrations. */
    public function resetSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $files = [$this->rootDir . '/schema.sql'];
        $migrations = glob($this->rootDir . '/migrations/*.sql') ?: [];
        sort($migrations);
        foreach ($migrations as $m) {
            $files[] = $m;
        }
        foreach ($files as $file) {
            $sql = (string)file_get_contents($file);
            $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS.*?;/is', '', $sql) ?? $sql;
            $sql = preg_replace('/USE\s+project_tracker\s*;/i', '', $sql) ?? $sql;
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                // If column already exists (1060) from updated schema.sql, ignore
                if (($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }
    }

    /** Insert the synthetic users straight into the test DB. @return array<string,string> name => id */
    public function seedUsers(): array
    {
        $ids = [];
        $stmt = $this->pdo->prepare('INSERT INTO users (id, name, password_hash, role, created_at) VALUES (:id, :name, :hash, :role, NOW())');
        foreach (self::SYNTHETIC_USERS as $name => [$role, $pin]) {
            $id = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, PHP_INT_MAX >> 32), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, PHP_INT_MAX >> 16));
            $stmt->execute([':id' => $id, ':name' => $name, ':hash' => password_hash($pin, PASSWORD_DEFAULT), ':role' => $role]);
            $ids[$name] = $id;
        }
        return $ids;
    }

    /** Start `php -S` with an explicit, minimal environment (no real .env values leak in). */
    public function startServer(): void
    {
        $this->startServerPool(1);
        $this->baseUrl = $this->baseUrls[0];
    }

    /** Start a pool of `php -S` servers on distinct ephemeral ports for true concurrency testing. */
    public function startServerPool(int $count = 4): array
    {
        if ($this->storageDir === '') {
            $this->storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pt_regression_' . bin2hex(random_bytes(4));
            mkdir($this->storageDir, 0750, true);
        }

        $env = [
            'DB_HOST' => $this->cfg['host'],
            'DB_PORT' => $this->cfg['port'],
            'DB_NAME' => $this->dbName,
            'DB_USER' => $this->cfg['user'],
            'DB_PASS' => $this->cfg['pass'],
            'PT_SESSION_SECRET' => bin2hex(random_bytes(32)),
            'PT_SESSION_COOKIE' => 'pt_session',
            'PT_STORAGE_DIR' => $this->storageDir,
            'PT_FILES_DIR' => $this->storageDir . '/files',
            'PT_BACKUP_DIR' => $this->storageDir . '/backup',
            'PT_PIN_MIN_LENGTH' => getenv('PT_PIN_MIN_LENGTH') ?: '10',
            'PT_SESSION_HOURS' => '12',
            'PT_MAX_UPLOAD_MB' => '10',
            'COOKIE_SECURE' => 'auto',
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'PATH' => getenv('PATH') ?: '',
            'TEMP' => sys_get_temp_dir(),
            'TMP' => sys_get_temp_dir(),
            'PT_ENABLE_APP_BACKUP' => getenv('PT_ENABLE_APP_BACKUP') ?: '0',
        ];

        $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $ports = [];
        for ($k = 0; $k < $count; $k++) {
            $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($sock === false) {
                throw new RuntimeException("No free port: {$errstr}");
            }
            $port = (int)explode(':', (string)stream_socket_get_name($sock, false))[1];
            fclose($sock);
            $ports[] = $port;
            $url = "http://127.0.0.1:{$port}";
            $this->baseUrls[] = $url;

            $publicDir = $this->rootDir . '/public';
            $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $publicDir, $publicDir . '/index.php'];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipes, $publicDir, $env);
            if (!is_resource($proc)) {
                throw new RuntimeException("Failed to start PHP test server on port {$port}");
            }
            $this->procs[] = $proc;
        }

        if ($this->baseUrl === '' && !empty($this->baseUrls)) {
            $this->baseUrl = $this->baseUrls[0];
        }

        // Wait until each server responds healthy
        foreach ($this->baseUrls as $url) {
            $probe = new HttpClient($url);
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                usleep(100000);
                $r = $probe->request('GET', '/api/health');
                if ($r['status'] === 200 && ($r['json']['ok'] ?? false) === true) {
                    $ready = true;
                    break;
                }
            }
            if (!$ready) {
                throw new RuntimeException("PHP test server at {$url} did not become ready");
            }
        }

        return $this->baseUrls;
    }

    /** New client, optionally logged in as a synthetic user. */
    public function client(?string $syntheticUser = null): HttpClient
    {
        $c = new HttpClient($this->baseUrl);
        if ($syntheticUser !== null) {
            $r = $c->login($syntheticUser, self::SYNTHETIC_USERS[$syntheticUser][1]);
            if ($r['status'] !== 200) {
                throw new RuntimeException("Login failed for {$syntheticUser}: HTTP {$r['status']}");
            }
        }
        return $c;
    }

    public function stopServer(): void
    {
        foreach ($this->procs as $proc) {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }
        $this->procs = [];
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
        $this->baseUrls = [];
        $this->baseUrl = '';
        if ($this->storageDir !== '' && is_dir($this->storageDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->storageDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->storageDir);
        }
    }
}

/** Tiny assertion helpers shared by test files. */
final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;

    public static function check(bool $ok, string $label, mixed $debug = null): void
    {
        if ($ok) {
            self::$passed++;
            echo "  [PASS] {$label}\n";
        } else {
            self::$failed++;
            echo "  [FAIL] {$label}" . ($debug !== null ? ' -> ' . json_encode($debug, JSON_UNESCAPED_UNICODE) : '') . "\n";
        }
    }

    public static function skip(string $label, string $why): void
    {
        self::$skipped++;
        echo "  [SKIP] {$label} ({$why})\n";
    }
}

