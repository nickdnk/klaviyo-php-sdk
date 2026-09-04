<?php


namespace nickdnk\Klaviyo\Services;

use Closure;
use JsonSerializable;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\MultipartBody;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\Resource;
use Psr\Http\Message\RequestInterface;

/**
 * A service's own resource path is {@see self::apiPath()}. Every other endpoint path it
 * touches (a bulk job family, a clone, render or upload endpoint, a report) is a `PATH_*`
 * constant on that service, never an inline string.
 */
abstract class BaseService
{

    /** @var Closure(string, string, array|JsonSerializable|MultipartBody|null, ?array, bool): (RequestInterface|array|null) */
    private Closure $makeRequest;

    public function __construct(Closure $makeRequest)
    {

        $this->makeRequest = $makeRequest;

    }

    /**
     * JSON bodies are wrapped in the JSON:API `{data: …}` envelope; a {@see MultipartBody}
     * is passed through as-is.
     *
     * @return array{data: Resource|Resource[], links: ?PaginationLinks}|null|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function request(string $method, string $endpoint, array|JsonSerializable|MultipartBody|null $body = null, ?array $query = null, bool $returnRequest = false): RequestInterface|array|null
    {

        if ($body !== null && !($body instanceof MultipartBody)) {
            $body = ['data' => $body];
        }

        return ($this->makeRequest)($method, $endpoint, $body, $query, $returnRequest);

    }

    /**
     * Implementing classes must return their base API path resource, i.e. `accounts`. Do not prefix with slash.
     * @return string
     */
    protected abstract function apiPath(): string;

}
