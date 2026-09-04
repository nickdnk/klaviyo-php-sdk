<?php


namespace nickdnk\Klaviyo\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Rate-limit state Klaviyo reports on every response. The headers follow the IETF RateLimit
 * draft: `RateLimit-Limit` names the quota of the window that currently binds, followed by every
 * window's quota (`10, 10;w=1, 150;w=60` = 10 per second burst, 150 per minute steady; some
 * endpoints add a daily window such as `225;w=86400`), `RateLimit-Remaining` is what is left in
 * the binding window, `RateLimit-Reset` the seconds until it resets, and on 429 `Retry-After`.
 * Limits are per endpoint tier and stricter when `additional-fields` or `include` are used, so
 * the numbers describe the last call made, not the account as a whole.
 *
 * @link https://developers.klaviyo.com/en/docs/rate_limits_and_error_handling
 */
final readonly class RateLimit
{

    /**
     * @param int|null              $limit             quota of the binding window (first number of `RateLimit-Limit`)
     * @param array<int, int>       $windows           quota per window length in seconds, e.g. `[1 => 10, 60 => 150]`
     * @param int|null              $remaining
     * @param int|null              $resetSeconds
     * @param int|null              $retryAfterSeconds only on 429
     * @param int                   $status            HTTP status of the response the headers came from
     */
    public function __construct(
        public ?int  $limit,
        public array $windows,
        public ?int  $remaining,
        public ?int  $resetSeconds,
        public ?int  $retryAfterSeconds,
        public int   $status,
    ) {}

    /**
     * Null when the response carries none of the rate-limit headers.
     */
    public static function fromResponse(ResponseInterface $response): ?self
    {

        $int = static function (string $header) use ($response): ?int {
            $v = trim($response->getHeaderLine($header));

            return $v !== '' && is_numeric($v) ? (int)$v : null;
        };

        [$limit, $windows] = self::parseLimit($response->getHeaderLine('RateLimit-Limit'));
        $remaining = $int('RateLimit-Remaining');
        $reset = $int('RateLimit-Reset');
        $retryAfter = $int('Retry-After');

        if ($limit === null && $remaining === null && $reset === null && $retryAfter === null) {
            return null;
        }

        return new self($limit, $windows, $remaining, $reset, $retryAfter, $response->getStatusCode());

    }

    /**
     * Quota of the shortest window: the burst limit, i.e. how many requests may be in flight at
     * once without tripping a 429. Null when unknown.
     */
    public function burstLimit(): ?int
    {

        if ($this->windows === []) {
            return $this->limit;
        }
        $shortest = min(array_keys($this->windows));

        return $this->windows[$shortest];

    }

    /**
     * True when the binding window is nearly exhausted; useful to pace bulk work before a 429.
     */
    public function isNearlyExhausted(int $threshold = 1): bool
    {

        return $this->remaining !== null && $this->remaining <= $threshold;

    }

    /**
     * `10, 10;w=1, 150;w=60` → [10, [1 => 10, 60 => 150]]; a bare number → [n, []]; empty → [null, []].
     *
     * @return array{0: ?int, 1: array<int, int>}
     */
    private static function parseLimit(string $header): array
    {

        $header = trim($header);
        if ($header === '') {
            return [null, []];
        }

        $limit = null;
        $windows = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            [$quota, $params] = array_pad(explode(';', $part, 2), 2, '');
            if (!is_numeric(trim($quota))) {
                continue;
            }
            $quota = (int)trim($quota);
            if (preg_match('/\bw=(\d+)/', $params, $m)) {
                $windows[(int)$m[1]] = $quota;
            } elseif ($limit === null) {
                $limit = $quota;
            }
        }
        ksort($windows);

        return [$limit ?? ($windows ? min($windows) : null), $windows];

    }

}
