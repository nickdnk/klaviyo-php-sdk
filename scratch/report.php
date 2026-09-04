<?php
/**
 * Merges scratch/results/*.json into scratch/REPORT.md and computes endpoint coverage
 * against the OpenAPI spec. Usage: php scratch/report.php
 */
declare(strict_types=1);

$results = [];
foreach (glob(__DIR__ . '/results/*.json') as $f) {
    $results[basename($f, '.json')] = json_decode(file_get_contents($f), true);
}
ksort($results);

// ── spec operations
$specFile = __DIR__ . '/openapi/stable.json';
if (!is_file($specFile)) {
    @mkdir(dirname($specFile));
    file_put_contents($specFile, file_get_contents('https://raw.githubusercontent.com/klaviyo/openapi/refs/heads/main/openapi/stable.json'));
}
$spec = json_decode(file_get_contents($specFile), true);
$ops = [];
foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $m => $op) {
        if (!in_array($m, ['get', 'post', 'patch', 'put', 'delete'], true)) continue;
        $regex = '#^' . preg_replace('#\\\\\{[^}]+\\\\\}#', '[^/]+', preg_quote(rtrim($path, '/'), '#')) . '/?$#';
        $ops[] = ['method' => strtoupper($m), 'path' => $path, 'id' => $op['operationId'] ?? '', 'tag' => $op['tags'][0] ?? '?', 'regex' => $regex, 'hits' => 0, 'ok' => 0];
    }
}
// longest literal path first so /api/profiles/{id}/lists beats /api/profiles/{id}
usort($ops, fn($a, $b) => substr_count($b['path'], '/') <=> substr_count($a['path'], '/') ?: strlen(preg_replace('/\{[^}]+\}/', '', $b['path'])) <=> strlen(preg_replace('/\{[^}]+\}/', '', $a['path'])));

$unmatched = [];
$totals = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$byClass = [];
$sdkMethods = [];
foreach ($results as $suite => $r) {
    foreach (['pass', 'fail', 'skip'] as $k) $totals[$k] += $r['summary'][$k] ?? 0;
    foreach ($r['steps'] as $s) {
        $sdkMethods["{$s['service']}.{$s['method']}"][$s['status']] = ($sdkMethods["{$s['service']}.{$s['method']}"][$s['status']] ?? 0) + 1;
        if ($s['status'] === 'fail') $byClass[$s['classification']][] = [$suite, $s];
        foreach ($s['http'] as $tx) {
            $path = parse_url($tx['url'], PHP_URL_PATH);
            $found = false;
            foreach ($ops as &$op) {
                if ($op['method'] === $tx['method'] && preg_match($op['regex'], $path)) {
                    $op['hits']++;
                    if (($tx['status'] ?? 0) < 400) $op['ok']++;
                    $found = true;
                    break;
                }
            }
            unset($op);
            if (!$found) $unmatched["{$tx['method']} {$path}"] = true;
        }
    }
}

// ── SDK method inventory (all public service methods) vs exercised
require __DIR__ . '/../vendor/autoload.php';
$inventory = [];
foreach (glob(__DIR__ . '/../src/Services/*Service.php') as $f) {
    $cls = 'nickdnk\\Klaviyo\\Services\\' . basename($f, '.php');
    $prop = lcfirst(preg_replace('/Service$/', '', basename($f, '.php')));
    $prop = match ($prop) { 'list' => 'lists', 'reporting' => 'reports', 'universalContent' => 'universalContent', default => $prop };
    // property names on APIClient are plural for most services
    $rc = new ReflectionClass($cls);
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->isConstructor()) continue;
        $inventory[basename($f, '.php')][] = $m->getName();
    }
}
$serviceProp = [];
$doc = (new ReflectionClass(nickdnk\Klaviyo\APIClient::class))->getDocComment();
preg_match_all('/@property-read\s+(\w+)\s+\$(\w+)/', $doc, $mm, PREG_SET_ORDER);
foreach ($mm as [$_, $cls, $prop]) $serviceProp[$cls] = $prop;

$out = [];
$out[] = "# Klaviyo PHP SDK — live smoke test report";
$out[] = "";
$out[] = "Generated " . date('c') . " from " . count($results) . " suite(s). Account: test account `WBhXHN`. Spec: Klaviyo OpenAPI stable, revision `{$spec['info']['version']}`.";
$out[] = "";
$out[] = "| Result | Count |\n|---|---|\n| pass | {$totals['pass']} |\n| fail | {$totals['fail']} |\n| skip | {$totals['skip']} |";
$out[] = "";
$out[] = "Classification of failures: `sdk-exception` = exception raised inside the SDK (hydration/type errors) → SDK bug. `api-rejected` = Klaviyo 4xx other than 403/404/409 → SDK request shape or test data; see request/response dump in `results/<suite>.json`. `account-limitation` = 402/403, feature not enabled on this account. `assertion` = call succeeded but the response did not match what the suite expected.";
$out[] = "";

$out[] = "## Failures by classification";
$out[] = "";
if (!$byClass) $out[] = "_none_";
ksort($byClass);
foreach ($byClass as $cls => $items) {
    $out[] = "### {$cls} (" . count($items) . ")";
    $out[] = "";
    $out[] = "| Suite | SDK call | Step | Reason |\n|---|---|---|---|";
    foreach ($items as [$suite, $s]) {
        $out[] = "| {$suite} | `{$s['service']}.{$s['method']}` | " . str_replace('|', '\\|', $s['label']) . " | " . str_replace(["|", "\n"], ['\\|', ' '], (string)$s['reason']) . " |";
    }
    $out[] = "";
}

$out[] = "## Skipped";
$out[] = "";
$out[] = "| Suite | SDK call | Step | Why |\n|---|---|---|---|";
foreach ($results as $suite => $r) foreach ($r['steps'] as $s) if ($s['status'] === 'skip') {
    $out[] = "| {$suite} | `{$s['service']}.{$s['method']}` | " . str_replace('|', '\\|', $s['label']) . " | " . str_replace('|', '\\|', $s['reason']) . " |";
}
$out[] = "";

$out[] = "## Account mutations to review";
$out[] = "";
foreach ($results as $suite => $r) foreach ($r['mutations'] as $m) $out[] = "- **{$suite}**: {$m}";
$out[] = "";
$out[] = "## Notes";
$out[] = "";
foreach ($results as $suite => $r) foreach ($r['notes'] as $n) $out[] = "- **{$suite}**: {$n}";
$out[] = "";

$out[] = "## SDK method coverage";
$out[] = "";
$notExercised = [];
$exercised = 0; $total = 0;
foreach ($inventory as $svc => $methods) {
    $prop = $serviceProp[$svc] ?? lcfirst(preg_replace('/Service$/', '', $svc));
    foreach (array_unique($methods) as $m) {
        $total++;
        $k = "{$prop}.{$m}";
        if (isset($sdkMethods[$k])) $exercised++; else $notExercised[] = "`{$k}`";
    }
}
$out[] = "{$exercised} of {$total} public service methods exercised.";
$out[] = "";
if ($notExercised) { $out[] = "Not exercised: " . implode(', ', $notExercised); $out[] = ""; }
$out[] = "| SDK call | pass | fail | skip |\n|---|---|---|---|";
ksort($sdkMethods);
foreach ($sdkMethods as $k => $c) $out[] = "| `{$k}` | " . ($c['pass'] ?? 0) . " | " . ($c['fail'] ?? 0) . " | " . ($c['skip'] ?? 0) . " |";
$out[] = "";

$out[] = "## OpenAPI endpoint coverage";
$out[] = "";
$hit = count(array_filter($ops, fn($o) => $o['hits'] > 0));
$ok = count(array_filter($ops, fn($o) => $o['ok'] > 0));
$out[] = count($ops) . " operations in spec; {$hit} called at least once; {$ok} returned a 2xx at least once.";
$out[] = "";
usort($ops, fn($a, $b) => [$a['tag'], $a['path'], $a['method']] <=> [$b['tag'], $b['path'], $b['method']]);
$tag = null;
foreach ($ops as $o) {
    if ($o['tag'] !== $tag) { $tag = $o['tag']; $out[] = ""; $out[] = "### {$tag}"; $out[] = ""; $out[] = "| Op | Endpoint | calls | 2xx |\n|---|---|---|---|"; }
    $flag = $o['hits'] === 0 ? '⬜' : ($o['ok'] > 0 ? '✅' : '❌');
    $out[] = "| {$flag} `{$o['id']}` | `{$o['method']} {$o['path']}` | {$o['hits']} | {$o['ok']} |";
}
$out[] = "";
if ($unmatched) { $out[] = "Requests not matching any spec operation: " . implode(', ', array_map(fn($k) => "`{$k}`", array_keys($unmatched))); $out[] = ""; }

$out[] = "## Per-suite step log";
$out[] = "";
foreach ($results as $suite => $r) {
    $out[] = "### {$suite} — pass {$r['summary']['pass']} / fail {$r['summary']['fail']} / skip {$r['summary']['skip']} ({$r['durationSec']}s, run `{$r['runId']}`)";
    $out[] = "";
    $out[] = "| | SDK call | Step | HTTP | Note |\n|---|---|---|---|---|";
    foreach ($r['steps'] as $s) {
        $icon = ['pass' => '✅', 'fail' => '❌', 'skip' => '⏭'][$s['status']];
        $http = implode('<br>', array_map(fn($t) => "{$t['method']} " . preg_replace('#^https://a\.klaviyo\.com/api/#', '', explode('?', $t['url'])[0]) . " → " . ($t['status'] ?? 'ERR'), $s['http']));
        $out[] = "| {$icon} | `{$s['service']}.{$s['method']}` | " . str_replace('|', '\\|', $s['label']) . " | {$http} | " . str_replace(["|", "\n"], ['\\|', ' '], (string)($s['reason'] ?? '')) . " |";
    }
    $out[] = "";
}

file_put_contents(__DIR__ . '/REPORT.md', implode("\n", $out) . "\n");
echo "wrote scratch/REPORT.md — pass {$totals['pass']} fail {$totals['fail']} skip {$totals['skip']}; endpoints hit {$hit}/" . count($ops) . "\n";
