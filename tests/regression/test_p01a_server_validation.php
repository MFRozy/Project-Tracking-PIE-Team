<?php

declare(strict_types=1);

/**
 * P0-1 (N01/F01) server side: every nested object of a project and an improvement is validated.
 * Ids/enums/links/numbers/dates/file refs -> HTTP 400; free text with " ' < > ; is stored verbatim (escaping is a render concern).
 * Also exports the stored marker data to <storage>/p01_fixture.json for the render test.
 */

/** @var TestEnv $env */

const P01_MARKER = 'Z9"\'<>;';

function p01_set(array &$a, string $path, mixed $value): void
{
    $ref = &$a;
    foreach (explode('.', $path) as $k) {
        if (!is_array($ref)) {
            $ref = [];
        }
        $key = ctype_digit($k) ? (int)$k : $k;
        if (!array_key_exists($key, $ref)) {
            $ref[$key] = [];
        }
        $ref = &$ref[$key];
    }
    $ref = $value;
}

function p01_project(string $id): array
{
    return [
        'id' => $id, 'name' => 'Proyek uji', 'code' => 'T-' . substr($id, -4), 'category' => 'Improvement',
        'status' => 'On Progress', 'priority' => 'Medium', 'pic' => 't_user1', 'area' => 'Line 1',
        'start' => '2026-01-01', 'end' => '2026-12-31', 'progress' => 10, 'desc' => 'deskripsi',
        'vendor' => 'Vendor', 'budget' => 1000, 'actual' => 0, 'po' => 'PO-1',
        'quotations' => [[
            'id' => 'xid-q1', 'vendor' => 'V1', 'amount' => 100, 'currency' => 'IDR', 'status' => 'Diterima',
            'date' => '2026-01-02', 'valid' => '2026-02-01', 'no' => 'Q-1', 'link' => 'https://example.com/q',
            'file' => 'PRJ-001_mv0t4qjc_bbc3fe_x.pdf', 'fileName' => 'x.pdf', 'note' => 'catatan', 'prevStatus' => 'Review',
        ]],
        'milestones' => [['id' => 'xid-m1', 'name' => 'Milestone', 'date' => '2026-03-01', 'done' => false]],
        'stages' => [[
            'id' => 'xid-s1', 'name' => 'Stage', 'pic' => 't_user1', 'ps' => '2026-01-01', 'pf' => '2026-01-10',
            'as' => '', 'af' => '', 'status' => 'Not Started', 'prog' => 0, 'note' => '', 'par' => false, 'days' => 5,
            'attachments' => [[
                'id' => 'xid-a1', 'name' => 'bukti.pdf', 'file' => 'PRJ-001_mv0t4qjc_bbc3fe_bukti.pdf', 'size' => 12,
                'type' => 'application/pdf', 'uploadedAt' => 1767225600000, 'uploadedBy' => 't_user1',
                'description' => 'Bukti hasil FAT sebelum perbaikan',
            ]],
            'docLinks' => [['id' => 'xid-d1', 'title' => 'Doc', 'url' => 'https://example.com/doc', 'description' => 'Laporan verifikasi lengkap']],
        ]],
        'pos' => [[
            'id' => 'xid-po1', 'no' => '001/PO/PI/I/2026', 'date' => '2026-01-05', 'qid' => 'xid-q1', 'vendor' => 'V1', 'currency' => 'USD',
            'addr' => 'Alamat', 'attn' => 'Attn', 'qno' => 'Q-1', 'subject' => 'Subjek',
            'items' => [['id' => 'xid-i1', 'desc' => 'Item', 'qty' => 2, 'unit' => 'Unit', 'price' => 50, 'due' => '2026-02-01']],
            'tax' => 11, 'terms' => 'T', 'delivery' => 'D', 'note' => 'N', 'by' => 't_user1', 'at' => 1767225600000,
            'shipName' => 'S', 'shipAddr' => 'SA', 'shipTel' => '021', 'shipAttn' => 'SAt', 'leadTime' => '2w',
            'tel' => '021', 'email' => 'a@b.c', 'useSig' => false,
        ]],
        'log' => [['t' => 1767225600000, 'by' => 't_user1', 'items' => ['Dibuat']]],
    ];
}

function p01_improvement(string $id): array
{
    return [
        'id' => $id, 'title' => 'Judul', 'department' => 'QAC', 'submittedBy' => 't_user1', 'submittedAt' => 1767225600000,
        'type' => 'quality', 'issue' => 'i', 'background' => 'b', 'purpose' => 'p', 'goals' => 'g', 'savingMp' => '', 'qualityMetric' => 'm',
        'attachments' => [['id' => 'xid-ia1', 'name' => 'foto.png', 'file' => 'IMP-abc_mv0twn8f_56bb9a_foto.png', 'size' => 10, 'type' => 'image/png']],
        'links' => [['id' => 'xid-il1', 'title' => 'Link', 'url' => 'https://example.com/x']],
        'status' => 'Baru', 'piOwner' => '', 'piFeedback' => '', 'piSuggestion' => '', 'piAction' => '', 'piReason' => '',
        'decision' => 'Pending', 'feedbackLog' => [], 'projectId' => '', 'updatedAt' => 0, 'updatedBy' => '',
    ];
}

function p01_post_project(HttpClient $c, array $p): array
{
    return $c->request('POST', '/api/projects', $p);
}

function p01_sync_imp(HttpClient $c, array $imp): array
{
    return $c->request('POST', '/api/sync', ['push' => ['improvements' => [$imp]], 'rev' => -1, 'sa' => 0]);
}

$u1 = $env->client('t_user1');
$admin = $env->client('t_admin');

// ---- baseline: valid payloads are accepted (guards against over-strict validation) ----
$r = p01_post_project($u1, p01_project('p01-base'));
T::check($r['status'] === 201, 'baseline valid project (all nested types) is accepted', [$r['status'], $r['json']]);
$r = p01_sync_imp($u1, p01_improvement('imp-base'));
T::check($r['status'] === 200, 'baseline valid improvement is accepted', [$r['status'], $r['json']]);

// ---- ids must match ^[A-Za-z0-9_-]{1,64}$ ----
$badId = 'a"b';
foreach ([
    'quotations.0.id', 'milestones.0.id', 'stages.0.id', 'stages.0.attachments.0.id', 'stages.0.docLinks.0.id',
    'pos.0.id', 'pos.0.qid', 'pos.0.items.0.id',
] as $i => $path) {
    $p = p01_project('p01-id' . $i);
    p01_set($p, $path, $badId);
    $r = p01_post_project($u1, $p);
    T::check($r['status'] === 400, "project id field {$path} rejects unsafe id (400)", $r['status']);
    $p = p01_project('p01-idlen' . $i);
    p01_set($p, $path, str_repeat('a', 65));
    T::check(p01_post_project($u1, $p)['status'] === 400, "project id field {$path} rejects 65-char id (400)");
}

foreach (['id', 'attachments.0.id', 'links.0.id', 'projectId'] as $i => $path) {
    $x = p01_improvement('imp-id' . $i);
    p01_set($x, $path, $badId);
    $r = p01_sync_imp($u1, $x);
    T::check($r['status'] === 400, "improvement field {$path} rejects unsafe id (400)", $r['status']);
}

// ---- enums / allow-lists ----
foreach ([
    'quotations.0.status' => '"><x', 'quotations.0.prevStatus' => '"><x', 'quotations.0.currency' => 'USD"><x',
    'pos.0.currency' => '"><x', 'stages.0.status' => 'x"y',
] as $path => $bad) {
    $p = p01_project('p01-en' . substr(md5($path), 0, 6));
    p01_set($p, $path, $bad);
    T::check(p01_post_project($u1, $p)['status'] === 400, "project enum {$path} rejects unknown value (400)");
}
foreach (['type' => 'x"y', 'status' => 'x"y', 'decision' => 'x"y', 'feedbackLog.0.status' => 'x"y', 'feedbackLog.0.decision' => 'x"y'] as $path => $bad) {
    $x = p01_improvement('imp-en' . substr(md5($path), 0, 6));
    p01_set($x, 'feedbackLog', [['by' => 'a', 'at' => 1, 'status' => 'Baru', 'decision' => 'Pending', 'feedback' => '', 'suggestion' => '', 'action' => '', 'reason' => '']]);
    p01_set($x, $path, $bad);
    T::check(p01_sync_imp($u1, $x)['status'] === 400, "improvement enum {$path} rejects unknown value (400)");
}

// ---- links, file references, numbers, dates, control chars, length ----
$cases = [
    'link javascript: (quotation)' => ['quotations.0.link', 'javascript:alert(1)'],
    'link data: (docLink)' => ['stages.0.docLinks.0.url', 'data:text/html,<script>1</script>'],
    'link with quote (docLink)' => ['stages.0.docLinks.0.url', 'https://e.com/"onmouseover="x'],
    'file ref traversal (quotation)' => ['quotations.0.file', '../../x"'],
    'file ref (stage attachment)' => ['stages.0.attachments.0.file', 'x"y.pdf'],
    'number (quotation amount)' => ['quotations.0.amount', '1;alert(1)'],
    'number (item qty)' => ['pos.0.items.0.qty', '<'],
    'number (item price)' => ['pos.0.items.0.price', '"1'],
    'number (po tax)' => ['pos.0.tax', 'x"'],
    'number (stage prog)' => ['stages.0.prog', 'x"'],
    'date (po date)' => ['pos.0.date', '"><'],
    'date (quotation date)' => ['quotations.0.date', '"><'],
    'date (quotation valid)' => ['quotations.0.valid', '2026-13-45'],
    'date (item due)' => ['pos.0.items.0.due', '"><'],
    'date (milestone)' => ['milestones.0.date', '"><'],
    'control char (milestone name)' => ['milestones.0.name', "ab\x01cd"],
    'control char (po note, NUL)' => ['pos.0.note', "ab\x00cd"],
    'length (quotation vendor)' => ['quotations.0.vendor', str_repeat('v', 5000)],
    'length (stage name)' => ['stages.0.name', str_repeat('v', 5000)],
    'non-scalar text (po vendor)' => ['pos.0.vendor', ['x' => 1]],
    'log timestamp' => ['log.0.t', 'x"'],
    'log items not strings' => ['log.0.items', [['a']]],
];
foreach ($cases as $label => [$path, $bad]) {
    $p = p01_project('p01-c' . substr(md5($label), 0, 8));
    p01_set($p, $path, $bad);
    $r = p01_post_project($u1, $p);
    T::check($r['status'] === 400, "rejects {$label} (400)", $r['status']);
}

$cases = [
    'link javascript: (improvement)' => ['links.0.url', 'javascript:alert(1)'],
    'link with quote (improvement)' => ['links.0.url', 'https://e.com/"x'],
    'file ref (improvement attachment)' => ['attachments.0.file', 'x"y.png'],
    'control char (improvement title)' => ['title', "a\x01b"],
    'length (improvement issue)' => ['issue', str_repeat('i', 20000)],
];
foreach ($cases as $label => [$path, $bad]) {
    $x = p01_improvement('imp-c' . substr(md5($label), 0, 8));
    p01_set($x, $path, $bad);
    T::check(p01_sync_imp($u1, $x)['status'] === 400, "rejects {$label} (400)");
}

// ---- marker text is stored verbatim in every free-text field ----
$M = P01_MARKER;
$textPaths = [
    'name', 'code', 'category', 'area', 'vendor', 'po', 'desc',
    'quotations.0.vendor', 'quotations.0.no', 'quotations.0.note', 'quotations.0.fileName',
    'milestones.0.name',
    'stages.0.name', 'stages.0.pic', 'stages.0.note', 'stages.0.attachments.0.name', 'stages.0.attachments.0.uploadedBy',
    'stages.0.attachments.0.description', 'stages.0.docLinks.0.title', 'stages.0.docLinks.0.description',
    'pos.0.no', 'pos.0.vendor', 'pos.0.addr', 'pos.0.attn', 'pos.0.qno', 'pos.0.subject', 'pos.0.terms', 'pos.0.delivery', 'pos.0.note', 'pos.0.by',
    'pos.0.shipName', 'pos.0.shipAddr', 'pos.0.shipTel', 'pos.0.shipAttn', 'pos.0.leadTime', 'pos.0.tel', 'pos.0.email',
    'pos.0.items.0.desc', 'pos.0.items.0.unit',
    'log.0.by', 'log.0.items.0',
];
$p = p01_project('p01-marker');
foreach ($textPaths as $path) {
    p01_set($p, $path, $M);
}
$p['code'] = 'Z9"\'<>;';
$r = p01_post_project($u1, $p);
T::check($r['status'] === 201, 'marker " \' < > ; accepted in every project free-text field', [$r['status'], $r['json']]);

$list = $admin->request('GET', '/api/projects');
$stored = null;
foreach (($list['json']['items'] ?? []) as $item) {
    if (($item['id'] ?? '') === 'p01-marker') {
        $stored = $item;
    }
}
$missing = [];
if ($stored === null) {
    $missing[] = '(project not returned)';
} else {
    foreach ($textPaths as $path) {
        $ref = $stored;
        foreach (explode('.', $path) as $k) {
            $ref = is_array($ref) ? ($ref[ctype_digit($k) ? (int)$k : $k] ?? null) : null;
        }
        if ($ref === null && $path === 'desc') {
            $ref = $stored['desc'] ?? $stored['description'] ?? null;
        }
        if ($ref !== $M) {
            $missing[] = $path;
        }
    }
}
T::check($missing === [], 'marker text comes back verbatim from the API (not mangled)', $missing);

$x = p01_improvement('imp-marker');
foreach (['title', 'department', 'issue', 'background', 'purpose', 'goals', 'savingMp', 'qualityMetric', 'piOwner', 'piFeedback', 'piSuggestion', 'piAction', 'piReason', 'attachments.0.name', 'links.0.title'] as $path) {
    p01_set($x, $path, $M);
}
$x['feedbackLog'] = [['by' => $M, 'at' => 1767225600000, 'status' => 'Baru', 'decision' => 'Pending', 'feedback' => $M, 'suggestion' => $M, 'action' => $M, 'reason' => $M]];
$r = p01_sync_imp($u1, $x);
T::check($r['status'] === 200, 'marker accepted in every improvement free-text field', [$r['status'], $r['json']]);

// category name (admin settings) carries the marker too, for the render test
$sa = (int)round(microtime(true) * 1000);
$r = $admin->request('POST', '/api/sync', ['push' => ['settings' => [
    'categories' => [['name' => $M, 'color' => '#2563eb'], ['name' => 'Improvement', 'color' => '#2563eb']],
    'settingsAt' => $sa,
]], 'rev' => -1, 'sa' => 0]);
T::check($r['status'] === 200, 'marker accepted as category name (admin settings)', $r['status']);

// ---- export fixture for the render test ----
$sync = $admin->request('POST', '/api/sync', ['push' => new stdClass(), 'rev' => -1, 'sa' => 0]);
$fixture = [
    'projects' => $sync['json']['projects'] ?? [],
    'improvements' => $sync['json']['improvements'] ?? [],
    'settings' => $sync['json']['settings'] ?? new stdClass(),
];
file_put_contents($env->storageDir . '/p01_fixture.json', json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// debugging aid: keep a copy outside the (deleted) temp storage so render_check.js can be run by hand
@copy($env->storageDir . '/p01_fixture.json', sys_get_temp_dir() . '/pt_p01_fixture.json');
T::check(count($fixture['projects']) >= 2 && count($fixture['improvements']) >= 2, 'fixture exported for render test', [count($fixture['projects']), count($fixture['improvements'])]);
