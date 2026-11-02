<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$env = new TestEnv();
$env->resetSchema();
$env->seedUsers();
$env->startServer();

try {
    $admin = $env->client('t_admin');
    $user1 = $env->client('t_user1');
    $anon = new HttpClient($env->baseUrl);

    // ------------------------------------------------------------------------
    // 1. /api/public without a session must NOT leak user names
    // ------------------------------------------------------------------------
    $pubRes = $anon->request('GET', '/api/public');
    T::check($pubRes['status'] === 200, '/api/public returns HTTP 200');
    $pubUsers = $pubRes['json']['users'] ?? [];
    $pubContrib = $pubRes['json']['contributors'] ?? [];
    $pubViewers = $pubRes['json']['viewers'] ?? [];
    T::check(
        empty($pubUsers) && empty($pubContrib) && empty($pubViewers),
        '/api/public without a session returns no user names or role groups',
        ['users' => $pubUsers, 'contributors' => $pubContrib, 'viewers' => $pubViewers]
    );

    // ------------------------------------------------------------------------
    // 2. PIN Validation: Weak PINs must be rejected on create-user & change-pin
    // ------------------------------------------------------------------------
    $weakPins = [
        'short9!' => 'Too short (< 10 chars)',
        'aaaaaaaaaa' => 'All same character (alpha)',
        '1111111111' => 'All same character (numeric)',
        '0123456789' => 'Sequential ascending numbers',
        '9876543210' => 'Sequential descending numbers',
        'abcdefghij' => 'Sequential ascending letters',
        'password123' => 'Common dictionary password',
    ];

    foreach ($weakPins as $pin => $reason) {
        $pinStr = (string)$pin;
        $res = $admin->request('POST', '/api/users', [
            'name' => 'u_' . substr(md5($pinStr), 0, 8),
            'pin' => $pinStr,
            'role' => 'user',
        ]);
        T::check($res['status'] === 400, "Weak PIN rejected ({$reason}): '{$pinStr}'", $res['status']);
    }

    // Strong PIN must be accepted
    $strongPin = 'Adm!n-Str0ng-2026';
    $resStrong = $admin->request('POST', '/api/users', [
        'name' => 'u_strong_user',
        'pin' => $strongPin,
        'role' => 'user',
    ]);
    T::check($resStrong['status'] === 200, "Strong PIN accepted: '{$strongPin}'", $resStrong['status']);

    // ------------------------------------------------------------------------
    // 3. Idle timeout & last_seen_at
    // ------------------------------------------------------------------------
    // Check that sessions table has last_seen_at column
    $cols = $env->pdo->query("SHOW COLUMNS FROM sessions LIKE 'last_seen_at'")->fetchAll();
    T::check(!empty($cols), "sessions table has last_seen_at column");

    // Check that user login records last_seen_at
    $testClient = new HttpClient($env->baseUrl);
    $loginRes = $testClient->login('u_strong_user', $strongPin);
    T::check($loginRes['status'] === 200, 'Login with strong PIN succeeded');

    // Simulate idle timeout by setting last_seen_at to 31 minutes ago
    $env->pdo->exec("UPDATE sessions SET last_seen_at = DATE_SUB(NOW(), INTERVAL 31 MINUTE)");

    // Next authenticated request should be rejected (401) due to idle timeout
    $idleCheck = $testClient->request('GET', '/api/me');
    T::check($idleCheck['status'] === 401, 'Idle session (> 30 min) is rejected with 401', $idleCheck['status']);

    // ------------------------------------------------------------------------
    // 4. isHttpsRequest: X-Forwarded-Proto trusted only from configured proxy
    // ------------------------------------------------------------------------
    // When sent without trusted proxy configuration, X-Forwarded-Proto: https is ignored
    $untrustedClient = new HttpClient($env->baseUrl);
    $probeRes = $untrustedClient->request('GET', '/api/health', null, ['X-Forwarded-Proto' => 'https']);
    // Check that cookie secure attribute isn't forced without trusted proxy
    $cookieHeader = $probeRes['headers']['set-cookie'] ?? '';
    T::check(!str_contains(strtolower($cookieHeader), 'secure'), 'X-Forwarded-Proto from untrusted IP does not enable secure cookie');

} finally {
    $env->stopServer();
}
