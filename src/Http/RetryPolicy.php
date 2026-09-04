<?php


namespace nickdnk\Klaviyo\Http;

use Closure;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * When and how long to wait before resending, following Klaviyo's guidance
 * (https://developers.klaviyo.com/en/docs/rate_limits_and_error_handling): retry 429 and 503,
 * never before the `Retry-After` the server asked for, otherwise on an exponential schedule
 * with randomness so many workers do not retry in lockstep.
 *
 * `Retry-After` wins as given (delay seconds or HTTP-date). Without it the delay is
 * `baseDelaySeconds × 2^(attempt-1)`, capped at `maxDelaySeconds` and spread by `jitterFactor`
 * (±50 % by default). 504 and 524 are gateway / edge timeouts from Klaviyo's CDN and retry the
 * same way; other 5xx and all other 4xx are final. Network failures (connect, timeout) retry
 * on the backoff schedule. Attempts are counted from 1, so `maxAttempts` is the total number
 * of sends.
 */
final readonly class RetryPolicy
{

    /** @var Closure(float): void */
    private Closure $sleep;

    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param list<int>                  $retryStatuses
     * @param float                      $jitterFactor    0 disables; 0.5 spreads a backoff delay over ±50 %
     * @param callable(float): void|null $sleep           seconds to pause; defaults to usleep
     * @param callable(): float|null     $random          uniform value in [0, 1); defaults to mt_rand
     * @param float                      $maxDelaySeconds cap for one computed backoff (before jitter); `Retry-After` is never capped
     */
    public function __construct(
        private int   $maxAttempts = 10,
        private float $baseDelaySeconds = 2.0,
        private array $retryStatuses = [429, 503, 504, 524],
        private float $jitterFactor = 0.5,
        ?callable              $sleep = null,
        ?callable              $random = null,
        private float $maxDelaySeconds = 60.0,
    )
    {

        $this->sleep = $sleep !== null
            ? $sleep(...)
            : static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int)round($seconds * 1_000_000));
                }
            };
        $this->random = $random !== null
            ? $random(...)
            : static fn(): float => mt_rand() / (mt_getrandmax() + 1);

    }

    /**
     * Seconds to wait before the next attempt, or null when the response is final.
     */
    public function delayForResponse(ResponseInterface $response, int $attempt): ?float
    {

        if ($attempt >= $this->maxAttempts || !in_array($response->getStatusCode(), $this->retryStatuses, true)) {
            return null;
        }

        return self::retryAfter($response) ?? $this->backoff($attempt);

    }

    /**
     * Seconds to wait before retrying a failed send, or null when the failure is final.
     */
    public function delayForException(Throwable $e, int $attempt): ?float
    {

        if ($attempt >= $this->maxAttempts || !($e instanceof NetworkExceptionInterface)) {
            return null;
        }

        return $this->backoff($attempt);

    }

    /**
     * Exponential backoff with jitter: `min(base × 2^(attempt-1), maxDelay)`, scaled by a
     * factor drawn uniformly from `[1 - jitter, 1 + jitter]`.
     */
    private function backoff(int $attempt): float
    {

        $delay = min($this->baseDelaySeconds * (2 ** max(0, $attempt - 1)), $this->maxDelaySeconds);
        if ($this->jitterFactor <= 0) {
            return $delay;
        }

        return $delay * (1 - $this->jitterFactor + 2 * $this->jitterFactor * ($this->random)());

    }

    public function sleep(float $seconds): void
    {

        ($this->sleep)($seconds);

    }

    /**
     * `Retry-After` as delay seconds or an HTTP-date; null when absent or unparsable.
     */
    private static function retryAfter(ResponseInterface $response): ?float
    {

        $header = trim($response->getHeaderLine('Retry-After'));
        if ($header === '') {
            return null;
        }
        if (is_numeric($header)) {
            return max(0.0, (float)$header);
        }

        $at = strtotime($header);

        return $at === false ? null : max(0.0, (float)($at - time()));

    }

}
