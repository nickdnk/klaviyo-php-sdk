<?php
/**
 * Usage: php scratch/run.php <suite> [<suite> ...]   or   php scratch/run.php all
 * Each suite is scratch/suites/<suite>.php returning function(Smoke\Harness $h): void.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/Harness.php';

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "usage: php run.php <suite>|all\n");
    exit(1);
}
if ($args === ['all']) {
    $args = array_map(fn($f) => basename($f, '.php'), glob(__DIR__ . '/suites/*.php'));
}

$exit = 0;
foreach ($args as $suite) {
    $file = __DIR__ . "/suites/{$suite}.php";
    if (!is_file($file)) {
        fwrite(STDERR, "no such suite: {$suite}\n");
        $exit = 1;
        continue;
    }
    $h = new Smoke\Harness($suite, oauth: str_starts_with($suite, 'oauth_'));
    try {
        (require $file)($h);
    } catch (Throwable $e) {
        $h->note('Suite aborted: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $exit = 1;
    } finally {
        $h->finish();
    }
}
exit($exit);
