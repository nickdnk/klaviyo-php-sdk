<?php


namespace nickdnk\Klaviyo;

use InvalidArgumentException;

/**
 * An OAuth access/refresh token pair as issued by Klaviyo's token endpoint. Immutable; a
 * refresh produces a new instance. Always persist the whole object, never just the access
 * token: the access token changes on every refresh and the refresh token may rotate (live
 * observation 2026-09: it is currently re-issued unchanged, but Klaviyo documents rotation).
 */
final readonly class OAuthCredentials
{

    /**
     * @param string      $accessToken
     * @param string      $refreshToken
     * @param int         $expiresAt    Unix timestamp at which the access token stops working.
     * @param string|null $scope        Space-separated scopes as granted by Klaviyo, when known.
     */
    public function __construct(
        public string  $accessToken,
        public string  $refreshToken,
        public int     $expiresAt,
        public ?string $scope = null,
    ) {}

    /**
     * Builds credentials from a decoded token endpoint response
     * (`access_token`, `refresh_token`, `expires_in`, `scope`).
     *
     * @param array<string, mixed> $response
     * @param int|null             $now      Unix timestamp the response was received at; defaults to `time()`.
     *
     * @throws InvalidArgumentException when the response lacks the required fields
     */
    public static function fromTokenResponse(array $response, ?int $now = null): self
    {

        $accessToken = $response['access_token'] ?? null;
        $refreshToken = $response['refresh_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;

        if (!is_string($accessToken) || $accessToken === ''
            || !is_string($refreshToken) || $refreshToken === ''
            || !is_numeric($expiresIn)) {
            throw new InvalidArgumentException('Token response is missing access_token, refresh_token or expires_in.');
        }

        $scope = $response['scope'] ?? null;

        return new self(
            $accessToken,
            $refreshToken,
            ($now ?? time()) + (int)$expiresIn,
            is_string($scope) && $scope !== '' ? $scope : null,
        );

    }

    /**
     * True when the access token has expired or will expire within `$graceSeconds`. Use it to
     * refresh proactively (see {@see APIClient::refreshCredentials()}) instead of waiting for
     * the first 401.
     */
    public function isExpired(int $graceSeconds = 60, ?int $now = null): bool
    {

        return $this->expiresAt < ($now ?? time()) + $graceSeconds;

    }

    /**
     * Granted scopes that this SDK knows about. Scopes Klaviyo adds later are skipped.
     *
     * @return list<OAuthScope>
     */
    public function scopes(): array
    {

        if ($this->scope === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $s) => OAuthScope::tryFrom($s),
            preg_split('/\s+/', trim($this->scope)) ?: [],
        )));

    }

}
