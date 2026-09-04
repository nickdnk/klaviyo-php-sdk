<?php
/**
 * Rebuilds tests/fixtures/responses/*.json from recorded live traffic.
 *
 *   php tests/fixtures/build.php <recordings-dir> [--clean]
 *
 * <recordings-dir> holds *.jsonl files, one JSON object per line:
 *   {"request":{"method":"GET","url":"https://a.klaviyo.com/api/…"},"response":{"status":200,"headers":{…},"body":"…"},
 *    "suite":"…","service":"…","method":"…"}
 * (the smoke harness in scratch/ writes exactly this with SMOKE_RECORD=1). Set FIXTURE_SCRUB to the test
 * account's own domain/organisation strings so they are neutralised (`term` or `term=replacement`, comma-separated).
 * One fixture is kept per
 * (OpenAPI operationId, HTTP status); richer bodies (with `included` / `relationships`) win, then the
 * smallest. Everything is scrubbed: tokens, secrets and any e-mail address outside the test domains.
 *
 * The operationId lookup needs Klaviyo's OpenAPI document; it is fetched from GitHub unless
 * KLAVIYO_OPENAPI_JSON points at a local copy.
 */
declare(strict_types=1);

$args = array_slice($argv, 1);
$clean = in_array('--clean', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--clean'));
$dir = $args[0] ?? null;
if (!$dir || !is_dir($dir)) {
    fwrite(STDERR, "usage: php tests/fixtures/build.php <recordings-dir> [--clean]\n");
    exit(1);
}
$out = __DIR__ . '/responses';
@mkdir($out);
if ($clean) {
    array_map('unlink', glob("{$out}/*.json") ?: []);
}

$specFile = getenv('KLAVIYO_OPENAPI_JSON') ?: null;
$specJson = $specFile ? file_get_contents($specFile) : file_get_contents('https://raw.githubusercontent.com/klaviyo/openapi/refs/heads/main/openapi/stable.json');
$spec = json_decode($specJson, true) ?: throw new RuntimeException('could not load the OpenAPI document');

$ops = [];
foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $m => $op) {
        if (!in_array($m, ['get', 'post', 'patch', 'put', 'delete'], true)) continue;
        $ops[] = ['method' => strtoupper($m), 'path' => $path, 'id' => $op['operationId'], 'regex' => '#^' . preg_replace('#\\\\\{[^}]+\\\\\}#', '[^/]+', preg_quote(rtrim($path, '/'), '#')) . '/?$#'];
    }
}
usort($ops, fn($a, $b) => substr_count($b['path'], '/') <=> substr_count($a['path'], '/') ?: strlen(preg_replace('/\{[^}]+\}/', '', $b['path'])) <=> strlen(preg_replace('/\{[^}]+\}/', '', $a['path'])));
$ops[] = ['method' => 'POST', 'path' => '/oauth/token', 'id' => 'oauth_token', 'regex' => '#^/oauth/token/?$#'];
$ops[] = ['method' => 'POST', 'path' => '/oauth/revoke', 'id' => 'oauth_revoke', 'regex' => '#^/oauth/revoke/?$#'];

$scrub = static function (?string $s): ?string {
    if ($s === null || $s === '') return $s;
    $s = preg_replace('/"(access_token|refresh_token|secret_key|client_secret|public_api_key)"\s*:\s*"[^"]*"/', '"$1":"REDACTED"', $s);
    $s = preg_replace('/(Klaviyo-API-Key|Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/', '$1 REDACTED', $s);
    // account identity strings to neutralise, from FIXTURE_SCRUB="domain.tld,Org Name" (domain → example.com, else "Example Org")
    // entries are `term` or `term=replacement`; a bare domain becomes example.com, anything else "Example Org"
    foreach (array_filter(array_map('trim', explode(',', (string)getenv('FIXTURE_SCRUB')))) as $entry) {
        [$term, $replacement] = array_pad(explode('=', $entry, 2), 2, null);
        $replacement ??= str_contains($term, '.') && !str_contains($term, ' ') ? 'example.com' : 'Example Org';
        $s = preg_replace('/' . preg_quote($term, '/') . '/i', $replacement, $s);
    }
    return preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', static function (array $m) {
        $e = $m[0];
        foreach (['@nickdnktech.com', '@example.com'] as $ok) {
            if (str_ends_with($e, $ok)) return $e;
        }
        return 'redacted-' . substr(sha1($e), 0, 8) . '@example.com';
    }, $s);
};

$best = [];
$seen = 0;
foreach (glob(rtrim($dir, '/') . '/*.jsonl') ?: [] as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $tx = json_decode($line, true);
        if (!$tx || empty($tx['response'])) continue;
        $seen++;
        $path = parse_url($tx['request']['url'], PHP_URL_PATH);
        $opId = null;
        foreach ($ops as $op) {
            if ($op['method'] === $tx['request']['method'] && preg_match($op['regex'], $path)) { $opId = $op['id']; break; }
        }
        if ($opId === null) { fwrite(STDERR, "unmatched: {$tx['request']['method']} {$path}\n"); continue; }
        $status = $tx['response']['status'];
        $body = $tx['response']['body'] ?? '';
        $key = "{$opId}.{$status}";
        $score = [str_contains($body, '"included"') ? 0 : 1, str_contains($body, '"relationships"') ? 0 : 1, strlen($body)];
        if (!isset($best[$key]) || $score < $best[$key]['score']) {
            parse_str((string)parse_url($tx['request']['url'], PHP_URL_QUERY), $query);
            $best[$key] = ['score' => $score, 'fixture' => [
                'operation' => $opId,
                'request'   => ['method' => $tx['request']['method'], 'path' => $path, 'query' => $query ?: new stdClass()],
                'response'  => [
                    'status'  => $status,
                    'headers' => array_map(fn($v) => is_array($v) ? implode(', ', $v) : $v, $tx['response']['headers'] ?? []),
                    'body'    => $body === '' ? null : (json_decode($body, true) ?? $body),
                ],
                'source'    => ['suite' => $tx['suite'] ?? null, 'step' => ($tx['service'] ?? '') . '.' . ($tx['method'] ?? ''), 'recorded' => date('Y-m-d')],
            ]];
        }
    }
}
ksort($best);
foreach ($best as $key => $b) {
    file_put_contents("{$out}/{$key}.json", $scrub(json_encode($b['fixture'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
}
$byStatus = [];
foreach (array_keys($best) as $k) { $s = explode('.', $k)[1]; $byStatus[$s] = ($byStatus[$s] ?? 0) + 1; }
ksort($byStatus);
printf("%d transactions → %d fixtures (%s), %d operations\n", $seen, count($best), implode(', ', array_map(fn($s, $n) => "{$s}: {$n}", array_keys($byStatus), $byStatus)), count(array_unique(array_map(fn($k) => explode('.', $k)[0], array_keys($best)))));
