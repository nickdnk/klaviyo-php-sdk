<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Psr7\Response;
use RuntimeException;

/**
 * Access to the recorded real API responses in tests/fixtures/responses (see the README there).
 * Names are `<operationId>.<status>`, e.g. `get_profile.200`, `create_list.201`, `get_lists.200`.
 */
final class Fixtures
{

    private const string DIR = __DIR__ . '/fixtures/responses';

    /** @var array<string, array> */
    private static array $cache = [];

    /** The whole fixture: request (method, path, query) and response (status, headers, body). */
    public static function load(string $name): array
    {

        if (!isset(self::$cache[$name])) {
            $file = self::DIR . "/{$name}.json";
            if (!is_file($file)) {
                throw new RuntimeException("No recorded fixture {$name} (expected {$file})");
            }
            self::$cache[$name] = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$cache[$name];

    }

    public static function exists(string $name): bool
    {

        return is_file(self::DIR . "/{$name}.json");

    }

    /** Decoded response body (JSON:API document) of a fixture. */
    public static function body(string $name): array
    {

        $body = self::load($name)['response']['body'];

        return is_array($body) ? $body : [];

    }

    /** The `data` member of a fixture (a resource object or a list of them). */
    public static function data(string $name): array
    {

        return self::body($name)['data'] ?? [];

    }

    /**
     * A Guzzle Response replaying the fixture, for MockHandler queues. `$mutate` may adjust the
     * decoded body (e.g. to set an id the test asserts on) before it is encoded.
     *
     * @param callable(array): array|null $mutate
     */
    public static function response(string $name, ?callable $mutate = null): Response
    {

        $f = self::load($name);
        $body = $f['response']['body'];
        if ($mutate !== null && is_array($body)) {
            $body = $mutate($body);
        }
        $contentType = $f['response']['headers']['content-type'] ?? 'application/vnd.api+json';

        return new Response(
            $f['response']['status'],
            ['Content-Type' => $contentType],
            $body === null ? '' : (is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : (string)$body),
        );

    }

    /**
     * Ids of the resources in a fixture's `data`, in order.
     *
     * @return list<string>
     */
    public static function ids(string $name): array
    {

        $data = self::data($name);
        if (isset($data['type'])) {
            return [(string)$data['id']];
        }

        return array_map(static fn(array $r) => (string)$r['id'], $data);

    }

}
