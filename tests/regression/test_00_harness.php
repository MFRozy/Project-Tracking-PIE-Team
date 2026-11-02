<?php

declare(strict_types=1);

/** Harness smoke test: proves the five synthetic users log in with the right roles and core controls still hold. */

/** @var TestEnv $env */

$expectedRoles = ['t_admin' => 'admin', 't_user1' => 'user', 't_user2' => 'user', 't_contrib' => 'contributor', 't_viewer' => 'viewer'];
$clients = [];
foreach ($expectedRoles as $name => $role) {
    $clients[$name] = $env->client($name);
    $me = $clients[$name]->request('GET', '/api/me');
    T::check($me['status'] === 200 && ($me['json']['user']['role'] ?? '') === $role, "login {$name} as {$role}", $me['json']);
}

$anon = $env->client();
T::check($anon->request('GET', '/api/projects')['status'] === 401, 'anonymous request is rejected (401)');

$c = $clients['t_admin'];
T::check($c->request('POST', '/api/projects', ['name' => 'x'], [], false)['status'] === 403, 'missing X-PT header is rejected (403)');
T::check($c->request('POST', '/api/projects', ['name' => 'x'], [], true, false)['status'] === 403, 'missing CSRF token is rejected (403)');
T::check($c->request('POST', '/api/projects', ['name' => 'x'], ['Origin' => 'http://evil.example'])['status'] === 403, 'foreign Origin is rejected (403)');

$bad = $env->client();
$r = $bad->login('t_user1', 'wrong-pin');
T::check($r['status'] === 401, 'wrong PIN is rejected (401)');
$env->pdo->exec('DELETE FROM login_attempts');

