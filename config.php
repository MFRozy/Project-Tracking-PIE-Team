<?php

declare(strict_types=1);

const APP_NAME = 'PI Project Management';
const APP_VERSION = '5.1.0';
const APP_ROOT = __DIR__;

function loadEnvFile(string $filePath): void
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $pos));
        $val = trim(substr($trimmed, $pos + 1));
        if ($key === '') {
            continue;
        }
        if (
            (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
        }
    }
}

loadEnvFile(APP_ROOT . '/.env');

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    if (is_string($value)) {
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
    }

    return $value;
}

define('STORAGE_DIR', rtrim((string)env('PT_STORAGE_DIR', APP_ROOT . '/storage'), '/\\'));
define('FILES_DIR', rtrim((string)env('PT_FILES_DIR', STORAGE_DIR . '/files'), '/\\'));
define('BACKUP_DIR', rtrim((string)env('PT_BACKUP_DIR', STORAGE_DIR . '/backup'), '/\\'));

function resolveSessionSecret(): string
{
    $fromEnv = (string)env('PT_SESSION_SECRET', '');
    if (strlen($fromEnv) >= 32) {
        return $fromEnv;
    }

    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0750, true);
    }

    $secretFile = STORAGE_DIR . '/.session_secret';
    if (is_file($secretFile) && is_readable($secretFile)) {
        $stored = trim((string)file_get_contents($secretFile));
        if (strlen($stored) >= 32) {
            return $stored;
        }
    }

    $generated = bin2hex(random_bytes(32));
    @file_put_contents($secretFile, $generated, LOCK_EX);
    @chmod($secretFile, 0600);
    return $generated;
}

define('DB_HOST', (string)env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int)env('DB_PORT', 3306));
define('DB_NAME', (string)env('DB_NAME', 'project_tracker'));
define('DB_USER', (string)env('DB_USER', 'pt_app'));
define('DB_PASS', (string)env('DB_PASS', ''));

define('SESSION_COOKIE', (string)env('PT_SESSION_COOKIE', 'pt_session'));
define('SESSION_SECRET', resolveSessionSecret());
define('SESSION_LIFETIME', max(300, (int)env('PT_SESSION_HOURS', 12) * 3600));
define('COOKIE_SECURE_MODE', (string)env('COOKIE_SECURE', 'auto'));
define('PIN_MIN_LENGTH', max(6, (int)env('PT_PIN_MIN_LENGTH', 6)));
define('MAX_UPLOAD_MB', max(1, (int)env('PT_MAX_UPLOAD_MB', 100)));
define('MAX_UPLOAD_BYTES', MAX_UPLOAD_MB * 1024 * 1024);
