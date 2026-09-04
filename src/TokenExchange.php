<?php


namespace nickdnk\Klaviyo;

use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Http\Transport;

/**
 * Performs the refresh-token grant against Klaviyo's token endpoint for one app. Handed to the
 * refresh callback of {@see APIClient::withOAuth()} so the callback can decide *whether* to
 * refresh without knowing the client id, secret or transport.
 *
 * Invoke it with the credentials whose refresh token should be spent (normally the ones
 * just reloaded from storage) and persist what comes back.
 */
final readonly class TokenExchange
{

    public function __construct(
        private string    $clientId,
        private string    $clientSecret,
        private Transport $transport,
    ) {}

    /**
     * Exchanges `$from->refreshToken` for a new token pair.
     *
     * @throws OAuthException      4xx from the token endpoint; `isInvalidGrant()` means the refresh token is revoked or expired
     * @throws ServerException
     * @throws ConnectionException
     */
    public function __invoke(OAuthCredentials $from): OAuthCredentials
    {

        return APIClient::refreshAccessToken($this->clientId, $this->clientSecret, $from->refreshToken, $this->transport);

    }

}
