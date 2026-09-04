<?php
/**
 * Diffs two Klaviyo OpenAPI documents: operations, their query parameters, and the attributes of
 * every resource schema. Use it when bumping APIClient::API_REVISION.
 *
 *   php scratch/spec-diff.php old-stable.json new-stable.json
 *
 * Klaviyo publishes the document at
 * https://raw.githubusercontent.com/klaviyo/openapi/<git-ref>/openapi/stable.json — keep a copy of the
 * revision the SDK is pinned to (scratch/openapi/ is git-ignored) or fetch it from the tag/commit that
 * carried it.
 */
declare(strict_types=1);

[$oldFile, $newFile] = [$argv[1] ?? null, $argv[2] ?? null];
if (!$oldFile || !$newFile || !is_file($oldFile) || !is_file($newFile)) {
    fwrite(STDERR, "usage: php scratch/spec-diff.php <old stable.json> <new stable.json>\n");
    exit(1);
}
$old = json_decode(file_get_contents($oldFile), true);
$new = json_decode(file_get_contents($newFile), true);
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
