<?php

declare(strict_types=1);

namespace Smoke;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\OAuthCredentials;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\TokenExchange;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Records every SDK call made by a smoke suite: which HTTP requests it produced, whether it
 * passed, and on failure the full request/response so the report can say whether the SDK,
 * the test data or the account is at fault.
 */
final class Harness
{

    public readonly string $suite;
    public readonly string $runId;
    public readonly array $env;
    public readonly APIClient $client;
    private readonly GuzzleTransport $transport;
    private readonly RetryPolicy $retry;

    /** @var list<array{request: RequestInterface, response: ?ResponseInterface, error: ?Throwable, options: array}> */
    private array $history = [];
    private array $steps = [];
    private array $mutations = [];
    private array $notes = [];
    /** @var list<array{label: string, fn: callable}> */
    private array $cleanups = [];
    private float $started;
    private bool $finished = false;

    public readonly bool $oauth;

    /**
     * @param bool $oauth authenticate with the OAuth credentials in scratch/.oauth.json (refreshed tokens are written back
     *                    through the SDK callback) instead of the private API key
     */
    public function __construct(string $suite, bool $oauth = false)
    {
        $this->oauth = $oauth;
        $this->suite = $suite;
        $this->runId = date('md') . substr(bin2hex(random_bytes(3)), 0, 4);
        $this->env = parse_ini_file(__DIR__ . '/../.env') ?: [];
        $this->started = microtime(true);

        $stack = HandlerStack::create();
        $stack->push(Middleware::history($this->history));
        $this->transport = GuzzleTransport::create(['handler' => $stack]);
        $this->retry = new RetryPolicy(maxAttempts: 8, baseDelaySeconds: 2.0);
        if ($oauth) {
            $this->client = $this->oauthClient($this->loadOAuth());
        } else {
            $this->client = APIClient::withApiKey($this->env['KLAVIYO_API_KEY'], $this->transport, $this->retry);
        }

        $this->log("suite {$suite} run {$this->runId}");
        register_shutdown_function(function () {
            if (!$this->finished) {
                $this->note('Suite did not call finish(); results written from shutdown handler.');
                $this->finish();
            }
        });
    }

    // region OAuth persistence (the "store credentials wherever" callback)

    /** OAuth client on the suite's transport/retry, starting from `$credentials`; shares the persistence callback. */
    public function oauthClient(OAuthCredentials $credentials): APIClient
    {
        return APIClient::withOAuth($credentials, $this->env['KLAVIYO_CLIENT_ID'], $this->env['KLAVIYO_CLIENT_SECRET'], $this->refreshOAuth(...), $this->transport, $this->retry);
    }

    /** SDK refresh callback: no locking needed here (single process), so exchange, persist, return. */
    public function refreshOAuth(OAuthCredentials $current, TokenExchange $exchange): OAuthCredentials
    {
        $fresh = $exchange($current);
        $this->saveOAuth($fresh);
        return $fresh;
    }

    public function loadOAuth(): OAuthCredentials
    {
        $j = json_decode((string)@file_get_contents(__DIR__ . '/../.oauth.json'), true)
            ?: throw new \RuntimeException('scratch/.oauth.json missing; run `php scratch/oauth.php link` + `exchange` first');

        return new OAuthCredentials($j['access_token'], $j['refresh_token'], (int)$j['expires_at'], $j['scope'] ?? null);
    }

    public function saveOAuth(OAuthCredentials $c): void
    {
        file_put_contents(__DIR__ . '/../.oauth.json', json_encode([
            'access_token' => $c->accessToken, 'refresh_token' => $c->refreshToken, 'expires_at' => $c->expiresAt,
            'scope' => $c->scope, 'saved_at' => date('c'),
        ], JSON_PRETTY_PRINT));
        $this->note('OAuth callback: new credentials persisted (expires ' . date('c', $c->expiresAt) . ')');
    }

    // endregion

    // region naming helpers

    public function name(string $what): string
    {
        return "sdk-smoke-{$this->runId}-{$what}";
    }

    public function email(string $tag): string
    {
        return "sdk-smoke+{$this->runId}-{$tag}@{$this->env['TEST_DOMAIN']}";
    }

    private ?string $senderEmail = null;

    /**
     * The account's verified default sender address (campaign/flow messages must use a verified sender).
     */
    public function senderEmail(): string
    {
        return $this->senderEmail ??= (string)($this->client->accounts->list()['data'][0]->contact_information->default_sender_email ?? throw new \RuntimeException('account has no default sender'));
    }

    public function webhookUrl(string $path = ''): string
    {
        return rtrim($this->env['WEBHOOK_BASE_URL'], '/') . '/' . ltrim($path ?: "klaviyo/{$this->runId}", '/');
    }

    // endregion

    // region recording

    /**
     * Runs one SDK call. Returns whatever $fn returns, or null when it threw (the failure is
     * recorded, the suite continues). $assert receives the result and may throw to fail the step.
     */
    public function step(string $service, string $method, string $label, callable $fn, ?callable $assert = null): mixed
    {
        $mark = count($this->history);
        $t0 = microtime(true);
        $entry = ['service' => $service, 'method' => $method, 'label' => $label, 'status' => 'pass', 'classification' => null, 'reason' => null, 'error' => null];
        $result = null;

        $callSucceeded = false;
        try {
            $result = $fn($this->client);
            $callSucceeded = true;
            $entry['result'] = $this->summarize($result);
            if ($assert !== null) {
                $assert($result);
            }
        } catch (Throwable $e) {
            $entry['status'] = 'fail';
            [$entry['classification'], $entry['reason']] = $this->classify($e);
            $entry['error'] = $this->describeError($e);
            // The SDK call itself succeeded and only the assertion failed: hand the result back anyway so
            // the suite can still register cleanup for whatever was created.
            if (!$callSucceeded) {
                $result = null;
            }
        }

        $entry['http'] = $this->httpSince($mark, $entry['status'] === 'fail');
        $this->record($mark, $service, $method, $label);
        $entry['ms'] = (int)round((microtime(true) - $t0) * 1000);
        $this->steps[] = $entry;
        $this->log(sprintf('%-4s %s.%s — %s%s', strtoupper($entry['status']), $service, $method, $label, $entry['reason'] ? " :: {$entry['reason']}" : ''));

        return $result;
    }

    public function skip(string $service, string $method, string $label, string $reason): void
    {
        $this->steps[] = ['service' => $service, 'method' => $method, 'label' => $label, 'status' => 'skip', 'classification' => 'skipped', 'reason' => $reason, 'error' => null, 'http' => [], 'ms' => 0];
        $this->log("SKIP {$service}.{$method} — {$label} :: {$reason}");
    }

    /** Overrides the auto classification of the most recent failed step (e.g. after inspecting the error). */
    public function reclassify(string $classification, ?string $reason = null): void
    {
        for ($i = count($this->steps) - 1; $i >= 0; $i--) {
            if ($this->steps[$i]['status'] === 'fail') {
                $this->steps[$i]['classification'] = $classification;
                if ($reason !== null) {
                    $this->steps[$i]['reason'] = $reason;
                }
                return;
            }
        }
    }

    /** Account state changed in a way that is not undone by cleanup (or is worth reviewing). */
    public function mutation(string $description): void
    {
        $this->mutations[] = $description;
        $this->log("MUTATION {$description}");
    }

    public function note(string $text): void
    {
        $this->notes[] = $text;
        $this->log("NOTE {$text}");
    }

    /** Registered cleanups run LIFO in finish(); each is recorded as a step. */
    public function cleanup(string $service, string $method, string $label, callable $fn): void
    {
        $this->cleanups[] = compact('service', 'method', 'label', 'fn');
    }

    public function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    /** Polls $poll every $interval seconds until it returns non-null/true or $timeout elapses. */
    public function waitFor(callable $poll, int $timeout = 180, int $interval = 5, string $what = 'condition'): mixed
    {
        $deadline = time() + $timeout;
        while (true) {
            $r = $poll();
            if ($r !== null && $r !== false) {
                return $r;
            }
            if (time() >= $deadline) {
                throw new AssertionFailed("Timed out after {$timeout}s waiting for {$what}");
            }
            sleep($interval);
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        foreach (array_reverse($this->cleanups) as $c) {
            $this->step($c['service'], $c['method'], 'cleanup: ' . $c['label'], $c['fn']);
        }

        $summary = ['pass' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($this->steps as $s) {
            $summary[$s['status']]++;
        }

        $out = [
            'suite' => $this->suite,
            'runId' => $this->runId,
            'startedAt' => date('c', (int)$this->started),
            'durationSec' => (int)round(microtime(true) - $this->started),
            'summary' => $summary,
            'steps' => $this->steps,
            'mutations' => $this->mutations,
            'notes' => $this->notes,
        ];
        file_put_contents(__DIR__ . "/../results/{$this->suite}.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->log(sprintf('DONE pass=%d fail=%d skip=%d (%ds)', $summary['pass'], $summary['fail'], $summary['skip'], $out['durationSec']));
    }

    // endregion

    // region recording of raw responses (fixture corpus for tests/RecordedResponsesTest)

    /**
     * With SMOKE_RECORD=1 in .env every transaction is appended to scratch/recordings/<suite>.jsonl with
     * full request/response bodies and headers, PII-scrubbed. scratch/fixtures.php turns these into
     * tests/fixtures/responses/*.json.
     */
    private function record(int $mark, string $service, string $method, string $label): void
    {
        if (empty($this->env['SMOKE_RECORD'])) {
            return;
        }
        $lines = '';
        foreach (array_slice($this->history, $mark) as $tx) {
            /** @var RequestInterface $req */
            $req = $tx['request'];
            $res = $tx['response'];
            $req->getBody()->rewind();
            $reqBody = (string)$req->getBody();
            $resBody = null;
            if ($res) {
                $res->getBody()->rewind();
                $resBody = (string)$res->getBody();
            }
            $lines .= json_encode([
                'suite' => $this->suite, 'runId' => $this->runId, 'service' => $service, 'method' => $method, 'label' => $label,
                'request' => ['method' => $req->getMethod(), 'url' => self::scrub((string)$req->getUri()), 'contentType' => $req->getHeaderLine('Content-Type'), 'body' => self::scrub($reqBody)],
                'response' => $res ? ['status' => $res->getStatusCode(), 'headers' => array_intersect_key(array_change_key_case($res->getHeaders(), CASE_LOWER), array_flip(['content-type', 'ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset', 'retry-after'])), 'body' => self::scrub($resBody)] : null,
                'error' => $tx['error'] ? get_class($tx['error']) . ': ' . $tx['error']->getMessage() : null,
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        }
        if ($lines !== '') {
            @mkdir(__DIR__ . '/../recordings');
            file_put_contents(__DIR__ . "/../recordings/{$this->suite}.jsonl", $lines, FILE_APPEND);
        }
    }

    /**
     * Removes anything that must not end up in a committed fixture: secrets, tokens, and any e-mail address that
     * is not one of ours.
     */
    /** @return list<string> */
    private static function scrubTerms(): array
    {
        $env = parse_ini_file(__DIR__ . '/../.env') ?: [];
        return array_values(array_filter(array_map('trim', explode(',', (string)($env['SCRUB_TERMS'] ?? '')))));
    }

    public static function scrub(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }
        $s = preg_replace('/"(access_token|refresh_token|secret_key|client_secret|public_api_key)"\s*:\s*"[^"]*"/', '"$1":"REDACTED"', $s);
        $s = preg_replace('/(Klaviyo-API-Key|Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/', '$1 REDACTED', $s);
        // the test account's own identity has nothing to do with the package: SCRUB_TERMS (comma-separated in .env)
        // lists the domain / organisation strings to neutralise; a domain becomes example.com, anything else "Example Org"
        // entries are `term` or `term=replacement`; a bare domain becomes example.com, anything else "Example Org"
        foreach (self::scrubTerms() as $entry) {
            [$term, $replacement] = array_pad(explode('=', $entry, 2), 2, null);
            $replacement ??= str_contains($term, '.') && !str_contains($term, ' ') ? 'example.com' : 'Example Org';
            $s = preg_replace('/' . preg_quote($term, '/') . '/i', $replacement, $s);
        }
        $s = preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', static function (array $m) {
            $e = $m[0];
            if (str_ends_with($e, '@nickdnktech.com') || str_ends_with($e, '@example.com')) {
                return $e;
            }
            return 'redacted-' . substr(sha1($e), 0, 8) . '@example.com';
        }, $s);
        return $s;
    }

    // endregion

    // region internals

    private function httpSince(int $mark, bool $withBodies): array
    {
        $out = [];
        foreach (array_slice($this->history, $mark) as $tx) {
            /** @var RequestInterface $req */
            $req = $tx['request'];
            $res = $tx['response'];
            $row = [
                'method' => $req->getMethod(),
                'url' => (string)$req->getUri(),
                'status' => $res?->getStatusCode(),
            ];
            if ($withBodies) {
                $req->getBody()->rewind();
                $row['requestBody'] = self::trim((string)$req->getBody());
                if ($res) {
                    $res->getBody()->rewind();
                    $row['responseBody'] = self::trim((string)$res->getBody());
                }
                if ($tx['error']) {
                    $row['transportError'] = get_class($tx['error']) . ': ' . $tx['error']->getMessage();
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private function classify(Throwable $e): array
    {
        if ($e instanceof AssertionFailed) {
            return ['assertion', $e->getMessage()];
        }
        if ($e instanceof ClientException) {
            $s = $e->getHttpStatus();
            $detail = $e->getFirstError()?->detail ?? $e->getFirstError()?->title ?? $e->getMessage();
            return match (true) {
                $s === 402 || $s === 403 => ['account-limitation', "HTTP {$s}: {$detail}"],
                $s === 404 => ['not-found', "HTTP 404: {$detail}"],
                $s === 409 => ['conflict', "HTTP 409: {$detail}"],
                $s === 429 => ['rate-limited', "HTTP 429 after retries: {$detail}"],
                default => ['api-rejected', "HTTP {$s}: {$detail}"],
            };
        }
        if ($e instanceof ServerException) {
            return ['server-error', $e->getMessage()];
        }
        if ($e instanceof ConnectionException) {
            return ['network', $e->getMessage() . ' / ' . $e->getPrevious()?->getMessage()];
        }
        if ($e instanceof OAuthException) {
            return ['oauth', $e->getMessage()];
        }
        return ['sdk-exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
    }

    private function describeError(Throwable $e): array
    {
        $d = ['class' => get_class($e), 'message' => $e->getMessage()];
        if ($e instanceof ClientException) {
            $d['httpStatus'] = $e->getHttpStatus();
            $d['errors'] = $e->getRawErrors();
        } elseif ($e instanceof ServerException) {
            $d['httpStatus'] = $e->getHttpStatus();
        } elseif (!$e instanceof AssertionFailed) {
            $d['trace'] = array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''), $e->getTrace()), 0, 8);
        }
        return $d;
    }

    private function summarize(mixed $r): mixed
    {
        if ($r instanceof IdentifiableResource) {
            return ['type' => $r::type(), 'id' => $r->id];
        }
        if (is_array($r) && array_key_exists('data', $r)) {
            $d = $r['data'];
            return ['count' => is_array($d) ? count($d) : ($d === null ? 0 : 1), 'next' => isset($r['links']) && $r['links']->next];
        }
        if (is_array($r)) {
            return ['count' => count($r)];
        }
        if ($r === null) {
            return null;
        }
        if (is_scalar($r)) {
            return $r;
        }
        return get_debug_type($r);
    }

    private static function trim(string $s, int $max = 4000): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . "…[+" . (strlen($s) - $max) . " bytes]" : $s;
    }

    private function log(string $line): void
    {
        $line = date('H:i:s') . ' ' . $line . "\n";
        fwrite(STDERR, $line);
        file_put_contents(__DIR__ . "/../logs/{$this->suite}.log", $line, FILE_APPEND);
    }

    // endregion

}

final class AssertionFailed extends \RuntimeException {}
