<?php
/**
 * Klaviyo's OpenAPI document per API revision. `api_versions/<revision>.url` holds a commit-pinned
 * raw.githubusercontent.com URL into klaviyo/openapi (MIT); downloads are cached in scratch/.cache/.
 */
declare(strict_types=1);

namespace Smoke;

use RuntimeException;

const LATEST_COMMIT_API = 'https://api.github.com/repos/klaviyo/openapi/commits?path=openapi/stable.json&per_page=1';

function specUrl(string $revision): string
{
    $file = __DIR__ . "/../api_versions/{$revision}.url";
    is_file($file) || throw new RuntimeException("no OpenAPI pin for revision {$revision} ({$file}); run php scratch/spec-diff.php --save");

    return trim(file_get_contents($file));
}

/** @return array<string, mixed> decoded document */
function loadSpec(string $revision): array
{
    $url = specUrl($revision);
    $cache = __DIR__ . '/../.cache/' . $revision . '-' . substr(sha1($url), 0, 12) . '.json';
    if (!is_file($cache)) {
        @mkdir(dirname($cache));
        file_put_contents($cache, file_get_contents($url) ?: throw new RuntimeException("could not fetch {$url}"));
    }

    return json_decode(file_get_contents($cache), true) ?: throw new RuntimeException("could not parse {$cache}");
}

/** @return array{url: string, json: string, spec: array<string, mixed>} the document at klaviyo/openapi main, pinned to its commit */
function fetchLatestSpec(): array
{
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: klaviyo-php-sdk-tooling\r\n"]]);
    $commits = json_decode(file_get_contents(LATEST_COMMIT_API, false, $ctx) ?: '', true);
    $sha = $commits[0]['sha'] ?? throw new RuntimeException('could not resolve the latest klaviyo/openapi commit');
    $url = "https://raw.githubusercontent.com/klaviyo/openapi/{$sha}/openapi/stable.json";
    $json = file_get_contents($url) ?: throw new RuntimeException("could not fetch {$url}");

    return ['url' => $url, 'json' => $json, 'spec' => json_decode($json, true) ?: throw new RuntimeException('could not parse the fetched document')];
}
