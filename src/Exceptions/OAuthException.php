<?php


namespace nickdnk\Klaviyo\Exceptions;

use Psr\Http\Message\ResponseInterface;

class OAuthException extends BaseException
{

    /**
     * One of `invalid_request`, `invalid_client`, `invalid_grant`, `unauthorized_client`,
     * `unsupported_grant_type`, `invalid_scope` or `unknown_error`.
     *
     * @var string
     */
    private string  $errorCode;
    private ?string $errorDescription;

    public function __construct(ResponseInterface $response)
    {

        parent::__construct('Klaviyo OAuth error');

        if ($json = json_decode((string)$response->getBody(), true)) {
            $this->errorCode = $json['error'] ?? 'unknown_error';
            $this->errorDescription = $json['error_description'] ?? null;
        } else {
            $this->errorCode = 'unknown_error';
            $this->errorDescription = 'Unexpected error received from Klaviyo. Please try again.';
        }
    }

    public function getErrorCode(): string
    {

        return $this->errorCode;
    }

    public function getErrorDescription(): ?string
    {

        return $this->errorDescription;
    }

    /**
     * The error returned if there is no technical problem with the integration, but if the refresh token has expired
     * or been revoked.
     *
     * @return bool
     */
    public function isInvalidGrant(): bool
    {

        return $this->errorCode === 'invalid_grant';
    }

}
