<?php
/**
 * Diffs two Klaviyo OpenAPI documents: operations, their query parameters, and the attributes of
 * every resource schema. Use it when bumping APIClient::API_REVISION.
 *
 *   php scratch/spec-diff.php                    pinned document for API_REVISION vs. klaviyo/openapi main
 *   php scratch/spec-diff.php --save             same, and pin main's commit as api_versions/<its revision>.url
 *   php scratch/spec-diff.php old.json new.json  any two local documents
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/spec.php';

use nickdnk\Klaviyo\APIClient;

$files = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--save'));
$save = in_array('--save', $argv, true);

if (count($files) === 2 && is_file($files[0]) && is_file($files[1])) {
    $old = json_decode(file_get_contents($files[0]), true);
    $new = json_decode(file_get_contents($files[1]), true);
} elseif ($files === []) {
    $old = Smoke\loadSpec(APIClient::API_REVISION);
    ['url' => $url, 'spec' => $new] = Smoke\fetchLatestSpec();
    $newRevision = $new['info']['version'] ?? throw new RuntimeException('fetched document has no info.version');
    if ($newRevision === APIClient::API_REVISION) {
        echo "Klaviyo main is still revision {$newRevision}; nothing to diff.\n";
        exit(0);
    }
    if ($save) {
        $pin = __DIR__ . "/api_versions/{$newRevision}.url";
        file_put_contents($pin, $url . "\n");
        fwrite(STDERR, "pinned {$pin} → {$url}\n");
    }
} else {
    fwrite(STDERR, "usage: php scratch/spec-diff.php [--save] | <old.json> <new.json>\n");
    exit(1);
}
printf("%s → %s\n\n", $old['info']['version'] ?? '?', $new['info']['version'] ?? '?');

$ops = static function (array $spec): array {
    $out = [];
    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $m => $op) {
            if (!in_array($m, ['get', 'post', 'patch', 'put', 'delete'], true)) continue;
            $params = [];
            foreach ($op['parameters'] ?? [] as $p) {
                if (isset($p['$ref'])) $p = $spec['components']['parameters'][basename($p['$ref'])];
                if (($p['in'] ?? '') === 'query') $params[] = $p['name'];
            }
            sort($params);
            $out[$op['operationId'] ?? strtoupper($m) . ' ' . $path] = ['method' => strtoupper($m), 'path' => $path, 'params' => $params, 'deprecated' => !empty($op['deprecated'])];
        }
    }
    return $out;
};
$attrs = static function (array $spec): array {
    $c = $spec['components']['schemas'];
    $deref = static function (array $s) use ($c) { while (isset($s['$ref'])) $s = $c[basename($s['$ref'])]; return $s; };
    $out = [];
    foreach ($c as $name => $sch) {
        if (!str_ends_with($name, 'ResourceObject') && !str_ends_with($name, 'ResourceObjectAttributes') && !str_ends_with($name, 'Resource')) continue;
        $a = $sch['properties']['attributes'] ?? (str_ends_with($name, 'Attributes') ? $sch : null);
        if (!$a) continue;
        $a = $deref($a);
        $type = isset($sch['properties']['type']) ? ($deref($sch['properties']['type'])['enum'][0] ?? null) : null;
        $key = ($type ? $type . ' ' : '') . $name;
        foreach ($a['properties'] ?? [] as $k => $v) {
            $v = $deref($v);
            $out[$key][$k] = ($v['type'] ?? (isset($v['oneOf']) ? 'oneOf' : 'object')) . (!empty($v['nullable']) ? '|null' : '') . (isset($v['enum']) ? ' {' . implode(',', array_map('strval', $v['enum'])) . '}' : '');
        }
    }
    return $out;
};

$o = $ops($old); $n = $ops($new);
$section = static function (string $title, array $lines) { if ($lines) { echo "## {$title}\n"; foreach ($lines as $l) echo "- {$l}\n"; echo "\n"; } };
$section('Operations added', array_map(fn($id) => "`{$id}` {$n[$id]['method']} {$n[$id]['path']}", array_values(array_diff(array_keys($n), array_keys($o)))));
$section('Operations removed', array_map(fn($id) => "`{$id}` {$o[$id]['method']} {$o[$id]['path']}", array_values(array_diff(array_keys($o), array_keys($n)))));
$section('Operations deprecated', array_values(array_filter(array_map(fn($id) => $n[$id]['deprecated'] && !$o[$id]['deprecated'] ? "`{$id}`" : null, array_intersect(array_keys($o), array_keys($n))))));
$changed = [];
foreach (array_intersect(array_keys($o), array_keys($n)) as $id) {
    $add = array_diff($n[$id]['params'], $o[$id]['params']); $rem = array_diff($o[$id]['params'], $n[$id]['params']);
    if ($add || $rem) $changed[] = "`{$id}`" . ($add ? ' +' . implode(' +', $add) : '') . ($rem ? ' -' . implode(' -', $rem) : '');
    if ($o[$id]['path'] !== $n[$id]['path']) $changed[] = "`{$id}` path {$o[$id]['path']} → {$n[$id]['path']}";
}
$section('Query parameters changed', $changed);

$oa = $attrs($old); $na = $attrs($new);
$lines = [];
foreach ($na as $schema => $fields) {
    if (!isset($oa[$schema])) { $lines[] = "NEW `{$schema}`: " . implode(', ', array_keys($fields)); continue; }
    foreach ($fields as $k => $t) {
        if (!isset($oa[$schema][$k])) $lines[] = "`{$schema}` +{$k} ({$t})";
        elseif ($oa[$schema][$k] !== $t) $lines[] = "`{$schema}` {$k}: {$oa[$schema][$k]} → {$t}";
    }
    foreach (array_diff_key($oa[$schema], $fields) as $k => $_) $lines[] = "`{$schema}` -{$k}";
}
foreach (array_diff_key($oa, $na) as $schema => $_) $lines[] = "REMOVED `{$schema}`";
$section('Resource attributes changed', $lines);
if (!$lines && !$changed) echo "(no attribute or parameter changes)\n";
