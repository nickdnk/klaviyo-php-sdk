<?php
/**
 * Deletes leftover `sdk-smoke-*` resources from aborted runs. Usage: php scratch/sweep.php [--dry-run]
 * Never touches profiles (use data-privacy jobs from the suites for those).
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;

$dry = in_array('--dry-run', $argv, true);
$env = parse_ini_file(__DIR__ . '/.env');
$c = APIClient::withApiKey($env['KLAVIYO_API_KEY']);
$prefix = 'sdk-smoke-';

$listAll = function (string $service, ?Query $q = null, int $max = 50) use ($c): array {
    $out = [];
    $next = null;
    do {
        $page = $next ? $c->$service->list(next: $next) : $c->$service->list($q);
        foreach ($page['data'] as $r) $out[] = $r;
        $next = $page['links']?->next;
    } while ($next && count($out) < 1000);
    return $out;
};
$matches = fn($r, string $attr = 'name') => str_starts_with((string)($r->$attr ?? ''), $prefix) || str_contains((string)$r->id, $prefix);

$targets = [
    // service, list query, name attribute
    ['campaigns', (new Query())->filter(Filter::equals('messages.channel', 'email'))->pageSize(50), 'name'],
    ['flows', (new Query())->pageSize(50), 'name'],
    ['segments', (new Query())->pageSize(10), 'name'],
    ['lists', (new Query())->pageSize(10), 'name'],
    ['tags', (new Query())->pageSize(50), 'name'],
    ['tagGroups', (new Query())->pageSize(25), 'name'],
    ['templates', (new Query())->pageSize(10), 'name'],
    ['universalContent', (new Query())->pageSize(10), 'name'],
    ['webFeeds', (new Query())->pageSize(20), 'name'],
    ['forms', (new Query())->pageSize(50), 'name'],
    ['customMetrics', null, 'name'],
    ['catalogVariants', (new Query())->pageSize(100), 'external_id'],
    ['catalogItems', (new Query())->pageSize(100), 'external_id'],
    ['catalogCategories', (new Query())->pageSize(100), 'external_id'],
    ['coupons', (new Query())->pageSize(50), 'external_id'],
    ['objectTypes', null, 'title'],
    ['dataSources', null, 'title'],
];

foreach ($targets as [$service, $q, $attr]) {
    try {
        $all = $listAll($service, $q);
    } catch (Throwable $e) {
        echo str_pad($service, 18), "list failed: ", substr($e->getMessage(), 0, 100), "\n";
        continue;
    }
    $mine = array_values(array_filter($all, fn($r) => $matches($r, $attr)));
    echo str_pad($service, 18), count($all), " total, ", count($mine), " leftover\n";
    foreach ($mine as $r) {
        echo "   ", $dry ? 'would delete ' : 'delete ', $r->id, ' (', $r->$attr ?? '', ")\n";
        if ($dry) continue;
        try {
            if ($service === 'flows' && ($r->status ?? '') !== 'draft') {
                $u = new \nickdnk\Klaviyo\Resources\Request\UpdateFlow($r->id);
                $u->status = 'draft';
                $c->flows->update($u);
            }
            $c->$service->delete($r->id);
        } catch (Throwable $e) {
            echo "   ! ", substr($e->getMessage(), 0, 120), "\n";
        }
    }
}
// images cannot be deleted: hide them
try {
    $imgs = $listAll('images', (new Query())->filter(Filter::equals('hidden', false))->pageSize(100));
    $mine = array_filter($imgs, fn($r) => str_starts_with((string)$r->name, $prefix));
    echo str_pad('images', 18), count($imgs), " visible, ", count($mine), " leftover (will hide)\n";
    foreach ($mine as $r) {
        if ($dry) continue;
        $u = new \nickdnk\Klaviyo\Resources\Request\UpdateImage($r->id);
        $u->hidden = true;
        $c->images->update($u);
    }
} catch (Throwable $e) {
    echo "images: ", substr($e->getMessage(), 0, 100), "\n";
}
