<?php


namespace nickdnk\Klaviyo\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Rate-limit state Klaviyo reports on every response: `RateLimit-Limit` (requests allowed per
 * window), `RateLimit-Remaining` (approximate requests left in the current window),
 * `RateLimit-Reset` (seconds until the window resets) and, on 429 only, `Retry-After`.
 * Limits are per endpoint tier (XS 1/s–15/m … XL 350/s–3500/m) and stricter when
 * `additional-fields` or `include` are used, so the numbers describe the last call made, not
 * the account as a whole.
 *
 * @link https://developers.klaviyo.com/en/docs/rate_limits_and_error_handling
 */
final readonly class RateLimit
{

    public function __construct(
        public ?int $limit,
        public ?int $remaining,
        public ?int $resetSeconds,
        public ?int $retryAfterSeconds,
        public int  $status,
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

        $limit = $int('RateLimit-Limit');
        $remaining = $int('RateLimit-Remaining');
        $reset = $int('RateLimit-Reset');
        $retryAfter = $int('Retry-After');

        if ($limit === null && $remaining === null && $reset === null && $retryAfter === null) {
            return null;
        }

        return new self($limit, $remaining, $reset, $retryAfter, $response->getStatusCode());

    }

    /**
     * True when the window is nearly exhausted; useful to pace bulk work before a 429 happens.
     */
    public function isNearlyExhausted(int $threshold = 1): bool
    {

        return $this->remaining !== null && $this->remaining <= $threshold;

    }

}
