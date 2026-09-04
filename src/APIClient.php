<?php

namespace nickdnk\Klaviyo;

use Closure;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use nickdnk\Klaviyo\Exceptions\BaseException;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Http\RateLimit;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\Http\Transport;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\TypeRegistry;
use nickdnk\Klaviyo\Services\AccountService;
use nickdnk\Klaviyo\Services\BackInStockSubscriptionService;
use nickdnk\Klaviyo\Services\CampaignMessageService;
use nickdnk\Klaviyo\Services\CampaignService;
use nickdnk\Klaviyo\Services\CatalogCategoryService;
use nickdnk\Klaviyo\Services\CatalogItemService;
use nickdnk\Klaviyo\Services\CatalogVariantService;
use nickdnk\Klaviyo\Services\ConversationMessageService;
use nickdnk\Klaviyo\Services\CouponCodeService;
use nickdnk\Klaviyo\Services\CouponService;
use nickdnk\Klaviyo\Services\CustomMetricService;
use nickdnk\Klaviyo\Services\DataSourceService;
use nickdnk\Klaviyo\Services\EventService;
use nickdnk\Klaviyo\Services\FlowActionService;
use nickdnk\Klaviyo\Services\FlowMessageService;
use nickdnk\Klaviyo\Services\FlowService;
use nickdnk\Klaviyo\Services\FormService;
use nickdnk\Klaviyo\Services\FormVersionService;
use nickdnk\Klaviyo\Services\ImageService;
use nickdnk\Klaviyo\Services\ListService;
use nickdnk\Klaviyo\Services\MappedMetricService;
use nickdnk\Klaviyo\Services\MetricPropertyService;
use nickdnk\Klaviyo\Services\MetricService;
use nickdnk\Klaviyo\Services\ObjectRecordService;
use nickdnk\Klaviyo\Services\ObjectSchemaService;
use nickdnk\Klaviyo\Services\ObjectTypeService;
use nickdnk\Klaviyo\Services\ProfileService;
use nickdnk\Klaviyo\Services\PushTokenService;
use nickdnk\Klaviyo\Services\ReportingService;
use nickdnk\Klaviyo\Services\ReviewService;
use nickdnk\Klaviyo\Services\SegmentService;
use nickdnk\Klaviyo\Services\SourceMappingService;
use nickdnk\Klaviyo\Services\TagGroupService;
use nickdnk\Klaviyo\Services\TagService;
use nickdnk\Klaviyo\Services\TemplateService;
use nickdnk\Klaviyo\Services\TrackingSettingService;
use nickdnk\Klaviyo\Services\UniversalContentService;
use nickdnk\Klaviyo\Services\WebFeedService;
use nickdnk\Klaviyo\Services\WebhookService;
use nickdnk\Klaviyo\Services\WebhookTopicService;
use nickdnk\Klaviyo\Webhooks\WebhookRequest;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Klaviyo JSON:API client over PSR-18 / PSR-17 / PSR-7. Pass any {@see Transport}; when none
 * is given, {@see GuzzleTransport} is used if Guzzle is installed. Rate-limit and network
 * retries follow {@see RetryPolicy}.
 *
 * Three ways to authenticate:
 *  - {@see self::withOAuth()}: OAuth credentials plus your app's client id/secret. A 401 makes the
 *    client refresh the tokens itself (once per request) and hand the new
 *    {@see OAuthCredentials} to the callback you supply, so you can persist them. Refresh
 *    tokens rotate on every refresh; losing the callback's payload means losing the connection.
 *  - `new APIClient($accessToken)`: a bare `Bearer` token, never refreshed. A 401 is final.
 *  - {@see self::withApiKey()}: a private API key (`Klaviyo-API-Key` header), never refreshed.
 *
 * @property-read AccountService                 $accounts
 * @property-read BackInStockSubscriptionService $backInStockSubscriptions
 * @property-read CampaignService                $campaigns
 * @property-read CampaignMessageService         $campaignMessages
 * @property-read CatalogCategoryService         $catalogCategories
 * @property-read CatalogItemService             $catalogItems
 * @property-read CatalogVariantService          $catalogVariants
 * @property-read ConversationMessageService     $conversationMessages
 * @property-read CouponCodeService              $couponCodes
 * @property-read CouponService                  $coupons
 * @property-read CustomMetricService            $customMetrics
 * @property-read DataSourceService              $dataSources
 * @property-read EventService                   $events
 * @property-read FlowActionService              $flowActions
 * @property-read FlowMessageService             $flowMessages
 * @property-read FlowService                    $flows
 * @property-read FormVersionService             $formVersions
 * @property-read FormService                    $forms
 * @property-read ImageService                   $images
 * @property-read ListService                    $lists
 * @property-read MappedMetricService            $mappedMetrics
 * @property-read MetricPropertyService          $metricProperties
 * @property-read MetricService                  $metrics
 * @property-read ObjectRecordService            $objectRecords
 * @property-read ObjectSchemaService            $objectSchemas
 * @property-read ObjectTypeService              $objectTypes
 * @property-read ProfileService                 $profiles
 * @property-read PushTokenService               $pushTokens
 * @property-read ReportingService               $reports
 * @property-read ReviewService                  $reviews
 * @property-read SegmentService                 $segments
 * @property-read SourceMappingService           $sourceMappings
 * @property-read TagGroupService                $tagGroups
 * @property-read TagService                     $tags
 * @property-read TemplateService                $templates
 * @property-read TrackingSettingService         $trackingSettings
 * @property-read UniversalContentService        $universalContent
 * @property-read WebFeedService                 $webFeeds
 * @property-read WebhookTopicService            $webhookTopics
 * @property-read WebhookService                 $webhooks
 */
class APIClient
{

    private const string API_BASE_URL  = 'https://a.klaviyo.com/api/';
    private const string AUTHORIZE_URL = 'https://www.klaviyo.com/oauth/authorize';
    private const string TOKEN_URL     = 'https://a.klaviyo.com/oauth/token';
    private const string REVOKE_URL    = 'https://a.klaviyo.com/oauth/revoke';
    /**
     * The Klaviyo API revision every request is sent with (`revision` header). The SDK's request
     * and response classes describe this revision; bump it deliberately after diffing the OpenAPI
     * document (see README "API revision").
     */
    public const string API_REVISION   = '2026-07-15';
    private const string CONTENT_TYPE  = 'application/vnd.api+json';
    private const string USER_AGENT    = 'nickdnk/klaviyo-php-sdk';

    /** Full `Authorization` header value: `Bearer …` or `Klaviyo-API-Key …`. */
    private string      $authorization;
    private Transport   $transport;
    private RetryPolicy $retry;
    private array       $services = [];

    private ?RateLimit        $lastRateLimit = null;
    private ?OAuthCredentials $credentials = null;
    private ?string           $clientId = null;
    private ?string           $clientSecret = null;

    /** @var (Closure(OAuthCredentials): void)|null */
    private ?Closure $onRefresh = null;

    /**
     * Process-wide transport used when a client or a static OAuth call gets none. Set through
     * {@see self::setDefaultTransport()} or scoped through {@see self::withTransport()}.
     */
    private static ?Transport $defaultTransport = null;

    /**
     * Client sending `$accessToken` as a `Bearer` token. Nothing is refreshed on 401; use
     * {@see self::withOAuth()} for that.
     */
    public function __construct(string $accessToken, ?Transport $transport = null, ?RetryPolicy $retry = null)
    {

        $this->authorization = 'Bearer ' . $accessToken;
        $this->transport = self::resolveTransport($transport);
        $this->retry = $retry ?? new RetryPolicy();

    }

    /**
     * Client authenticated with OAuth credentials that it keeps fresh on its own. On a 401 it
     * calls {@see self::refreshCredentials()} and retries the request once with the new access
     * token. Every refresh, automatic or explicit, invokes `$onRefresh` with the new
     * {@see OAuthCredentials} before the retry goes out; persist them there.
     *
     * The callback is the only place the SDK reaches back into your application. It should
     * store the credentials and return, nothing more. If it throws, the exception propagates out
     * of the API call that triggered the refresh.
     *
     * Refresh failures surface as {@see OAuthException} (check `isInvalidGrant()` for a revoked
     * or expired refresh token), {@see ServerException} or {@see ConnectionException}.
     *
     * @param callable(OAuthCredentials): void $onRefresh
     */
    public static function withOAuth(OAuthCredentials $credentials, string $clientId, string $clientSecret,
        callable $onRefresh, ?Transport $transport = null, ?RetryPolicy $retry = null
    ): self
    {

        $client = new self($credentials->accessToken, $transport, $retry);
        $client->credentials = $credentials;
        $client->clientId = $clientId;
        $client->clientSecret = $clientSecret;
        $client->onRefresh = $onRefresh(...);

        return $client;

    }

    /**
     * Client authenticated with a private API key (`pk_…`) instead of an OAuth token. A 401
     * is final here, since there is nothing to refresh.
     *
     * @link https://developers.klaviyo.com/en/docs/authenticate_#private-key-authentication
     */
    public static function withApiKey(string $privateApiKey, ?Transport $transport = null, ?RetryPolicy $retry = null): self
    {

        $client = new self('', $transport, $retry);
        $client->authorization = 'Klaviyo-API-Key ' . $privateApiKey;

        return $client;

    }

    // region Transport selection

    /**
     * Runs $fn with $transport as the default for every client and OAuth call created inside
     * it, then restores the previous default even if $fn throws. Intended for tests.
     */
    public static function withTransport(Transport $transport, callable $fn): mixed
    {

        $prev = self::$defaultTransport;
        self::$defaultTransport = $transport;
        try {
            return $fn();
        } finally {
            self::$defaultTransport = $prev;
        }

    }

    public static function setDefaultTransport(?Transport $transport): void
    {

        self::$defaultTransport = $transport;

    }

    private static function resolveTransport(?Transport $explicit): Transport
    {

        if ($explicit !== null) {
            return $explicit;
        }
        if (self::$defaultTransport !== null) {
            return self::$defaultTransport;
        }
        if (class_exists(\GuzzleHttp\Client::class)) {
            return self::$defaultTransport = GuzzleTransport::create();
        }

        throw new LogicException(
            'No HTTP transport available for the Klaviyo client. Pass a Transport (e.g. Psr18Transport::create($psr18Client)) '
            . 'to the constructor or APIClient::setDefaultTransport(); guzzlehttp/guzzle is picked up automatically when installed.'
        );

    }

    // endregion

    /**
     * Replaces the bearer token on a client built with the constructor. For an OAuth client use
     * {@see self::setCredentials()} so the refresh token stays in sync too.
     */
    public function updateAccessToken(string $accessToken): void
    {

        $this->authorization = 'Bearer ' . $accessToken;

    }

    /**
     * Rate-limit headers of the most recent non-pooled response (`RateLimit-Limit`,
     * `RateLimit-Remaining`, `RateLimit-Reset`, `Retry-After`), or null before the first call.
     * Retries are transparent: this reflects the final response of the last request.
     */
    public function getLastRateLimit(): ?RateLimit
    {

        return $this->lastRateLimit;

    }

    // region OAuth credentials

    /**
     * Current credentials of a client built with {@see self::withOAuth()}; null otherwise.
     */
    public function getCredentials(): ?OAuthCredentials
    {

        return $this->credentials;

    }

    /**
     * Swaps in credentials obtained elsewhere, e.g. refreshed by another process and reloaded
     * from storage. Does not call the refresh callback. Only valid on an OAuth client.
     *
     * @throws LogicException when the client was not built with {@see self::withOAuth()}
     */
    public function setCredentials(OAuthCredentials $credentials): void
    {

        if ($this->credentials === null) {
            throw new LogicException('setCredentials() requires a client built with APIClient::withOAuth().');
        }

        $this->credentials = $credentials;
        $this->authorization = 'Bearer ' . $credentials->accessToken;

    }

    /**
     * Refreshes the tokens now, regardless of expiry, using the stored refresh token. The
     * client switches to the new access token and the refresh callback receives the new
     * credentials before this returns. Call it proactively when
     * {@see OAuthCredentials::isExpired()} says the token is about to lapse; any locking
     * needed to keep concurrent workers from refreshing at once belongs in the caller.
     *
     * @throws LogicException when the client was not built with {@see self::withOAuth()}
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function refreshCredentials(): OAuthCredentials
    {

        if ($this->credentials === null || $this->clientId === null || $this->clientSecret === null || $this->onRefresh === null) {
            throw new LogicException('refreshCredentials() requires a client built with APIClient::withOAuth().');
        }

        $credentials = self::refreshAccessToken(
            $this->clientId, $this->clientSecret, $this->credentials->refreshToken, $this->transport
        );

        $this->credentials = $credentials;
        $this->authorization = 'Bearer ' . $credentials->accessToken;

        ($this->onRefresh)($credentials);

        return $credentials;

    }

    // endregion

    public function __get(string $name): Services\BaseService
    {

        return $this->services[$name] ??= match ($name) {
            'accounts'                 => new AccountService($this->makeRequest(...)),
            'backInStockSubscriptions' => new BackInStockSubscriptionService($this->makeRequest(...)),
            'campaigns'                => new CampaignService($this->makeRequest(...)),
            'campaignMessages'         => new CampaignMessageService($this->makeRequest(...)),
            'catalogCategories'        => new CatalogCategoryService($this->makeRequest(...)),
            'catalogItems'             => new CatalogItemService($this->makeRequest(...)),
            'catalogVariants'          => new CatalogVariantService($this->makeRequest(...)),
            'conversationMessages'     => new ConversationMessageService($this->makeRequest(...)),
            'couponCodes'              => new CouponCodeService($this->makeRequest(...)),
            'coupons'                  => new CouponService($this->makeRequest(...)),
            'customMetrics'            => new CustomMetricService($this->makeRequest(...)),
            'dataSources'              => new DataSourceService($this->makeRequest(...)),
            'events'                   => new EventService($this->makeRequest(...)),
            'flowActions'              => new FlowActionService($this->makeRequest(...)),
            'flowMessages'             => new FlowMessageService($this->makeRequest(...)),
            'flows'                    => new FlowService($this->makeRequest(...)),
            'formVersions'             => new FormVersionService($this->makeRequest(...)),
            'forms'                    => new FormService($this->makeRequest(...)),
            'images'                   => new ImageService($this->makeRequest(...)),
            'lists'                    => new ListService($this->makeRequest(...)),
            'mappedMetrics'            => new MappedMetricService($this->makeRequest(...)),
            'metricProperties'         => new MetricPropertyService($this->makeRequest(...)),
            'metrics'                  => new MetricService($this->makeRequest(...)),
            'objectRecords'            => new ObjectRecordService($this->makeRequest(...)),
            'objectSchemas'            => new ObjectSchemaService($this->makeRequest(...)),
            'objectTypes'              => new ObjectTypeService($this->makeRequest(...)),
            'profiles'                 => new ProfileService($this->makeRequest(...)),
            'pushTokens'               => new PushTokenService($this->makeRequest(...)),
            'reports'                  => new ReportingService($this->makeRequest(...)),
            'reviews'                  => new ReviewService($this->makeRequest(...)),
            'segments'                 => new SegmentService($this->makeRequest(...)),
            'sourceMappings'           => new SourceMappingService($this->makeRequest(...)),
            'tagGroups'                => new TagGroupService($this->makeRequest(...)),
            'tags'                     => new TagService($this->makeRequest(...)),
            'templates'                => new TemplateService($this->makeRequest(...)),
            'trackingSettings'         => new TrackingSettingService($this->makeRequest(...)),
            'universalContent'         => new UniversalContentService($this->makeRequest(...)),
            'webFeeds'                 => new WebFeedService($this->makeRequest(...)),
            'webhookTopics'            => new WebhookTopicService($this->makeRequest(...)),
            'webhooks'                 => new WebhookService($this->makeRequest(...)),
            default                    => throw new InvalidArgumentException(sprintf('Unknown Klaviyo service "%s".', $name)),
        };

    }

    /**
     * Parses and verifies an incoming Klaviyo webhook request. The HMAC check runs before any
     * JSON parsing so unsigned/forged bodies cost nothing beyond a hash and never reach the
     * JSON parser. Returns null on missing headers, signature mismatch, malformed body, stale
     * timestamp, or any other shape problem — caller treats failure uniformly.
     *
     * Each event's `topic` is a {@see Resources\Shared\WebhookTopic} (compare with
     * `$topic->is(WebhookTopic::OPENED_EMAIL)` or by id); topics are open-ended, every metric on
     * the account is one, and no event is dropped for an unfamiliar topic. The `payload` is the
     * hydrated resource (an {@see Event} for `event:` topics) or the raw array when its `type` is
     * unknown.
     *
     * Verified live: signature = hex HMAC-SHA256 of `body . Klaviyo-Timestamp` with the webhook's
     * secret. The `Klaviyo-Timestamp` header is only ever used as HMAC input, never for the
     * freshness check. Known Klaviyo defect, observed 2026-09-04: the header is an HTTP-date with
     * a wrong clock (`Fri, 04 Sep 2026 18:16:52 GMT` sent at 13:16:52 GMT, i.e. local time
     * mislabelled as GMT). Replay protection compares `$now` against the body's `meta.timestamp`
     * (ISO 8601, correct) with `$tolerance` seconds of slack; a body without `meta.timestamp`
     * falls back to the header and will therefore normally be rejected as stale.
     */
    public static function parseWebhookRequest(
        ServerRequestInterface $request,
        string $secret,
        int $tolerance = 300,
        ?int $now = null,
    ): ?WebhookRequest {

        $signature = $request->getHeaderLine('Klaviyo-Signature');
        $hmacTimestamp = $request->getHeaderLine('Klaviyo-Timestamp');

        if (!$signature || !$hmacTimestamp) {
            return null;
        }

        $body = (string)$request->getBody();

        if (!hash_equals(hash_hmac('sha256', $body . $hmacTimestamp, $secret), $signature)) {
            return null;
        }

        $json = json_decode($body, true);

        if (!is_array($json) || !is_array($json['data'] ?? null)) {
            return null;
        }

        $webhookId = $json['meta']['klaviyo_webhook_id'] ?? null;
        $apiVersion = $json['meta']['version'] ?? null;
        $timestamp = $json['meta']['timestamp'] ?? $hmacTimestamp;

        if (!$webhookId || !is_string($webhookId) || !$apiVersion || !is_string($apiVersion)) {
            return null;
        }

        $eventTime = strtotime($timestamp);
        if ($eventTime === false || abs(($now ?? time()) - $eventTime) > $tolerance) {
            return null;
        }

        $events = [];
        foreach ($json['data'] as $event) {
            if (!is_array($event) || !is_string($event['topic'] ?? null) || $event['topic'] === '') {
                continue;
            }
            $payload = $event['payload']['data'] ?? null;
            $events[] = [
                'topic'   => new Resources\Response\WebhookTopic($event['topic']),
                'payload' => is_array($payload) ? (self::hydrateResource($payload) ?? $payload) : null,
            ];
        }

        return new WebhookRequest($webhookId, $timestamp, $apiVersion, $events);

    }

    // region OAuth / Static

    public static function generateCodeVerifier(): string
    {

        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');

    }

    public static function generateCodeChallenge(string $codeVerifier): string
    {

        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

    }

    /**
     * @param OAuthScope[] $scopes
     */
    public static function getOAuthLink(string $clientId, string $state, string $codeVerifier, array $scopes, string $redirectUri): string
    {

        return self::AUTHORIZE_URL . '?' . http_build_query(
                [
                    'response_type'         => 'code',
                    'client_id'             => $clientId,
                    'redirect_uri'          => $redirectUri,
                    'scope'                 => implode(' ', array_map(fn(OAuthScope $s) => $s->value, $scopes)),
                    'state'                 => $state,
                    'code_challenge_method' => 'S256',
                    'code_challenge'        => self::generateCodeChallenge($codeVerifier)
                ]
            );

    }

    /**
     * Completes the authorization-code flow started with {@see self::getOAuthLink()}. Persist the
     * returned credentials, then build a client with {@see self::withOAuth()}.
     *
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public static function exchangeCodeForToken(string $clientId, string $clientSecret, string $code,
        string $codeVerifier, string $redirectUri, ?Transport $transport = null
    ): OAuthCredentials
    {

        return OAuthCredentials::fromTokenResponse(self::tokenRequest($clientId, $clientSecret, self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
        ], $transport));

    }

    /**
     * Exchanges a refresh token for a new token pair. Clients built with {@see self::withOAuth()}
     * call this for you; use it directly only when managing tokens without a client instance.
     *
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public static function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken,
        ?Transport $transport = null
    ): OAuthCredentials
    {

        return OAuthCredentials::fromTokenResponse(self::tokenRequest($clientId, $clientSecret, self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $transport));

    }

    /**
     * @link https://developers.klaviyo.com/en/docs/set_up_oauth
     *
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public static function revokeToken(string $clientId, string $clientSecret, string $token, ?Transport $transport = null): void
    {

        self::tokenRequest($clientId, $clientSecret, self::REVOKE_URL, [
            'token'           => $token,
            'token_type_hint' => 'refresh_token',
        ], $transport);

    }

    /**
     * Form-encoded POST to the OAuth endpoints with HTTP basic client credentials. 4xx is an
     * OAuth error (invalid grant, bad client, …), 5xx a server error; anything else decodes
     * as JSON (empty for revoke).
     *
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    private static function tokenRequest(string $clientId, string $clientSecret, string $url, array $form,
        ?Transport $transport
    ): array
    {

        $transport = self::resolveTransport($transport);

        $request = $transport->requestFactory()
            ->createRequest('POST', $url)
            ->withHeader('Authorization', sprintf('Basic %s', base64_encode($clientId . ':' . $clientSecret)))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withBody($transport->streamFactory()->createStream(http_build_query($form)));

        $response = self::sendWithRetry($transport, new RetryPolicy(), $request);
        $status = $response->getStatusCode();

        if ($status >= 500) {
            throw new ServerException($response);
        }
        if ($status >= 400) {
            throw new OAuthException($response);
        }

        $contents = (string)$response->getBody();

        return $contents === '' ? [] : (json_decode($contents, true) ?: []);

    }

    // endregion

    // region Internal

    /**
     * Builds, sends and decodes one API request. A {@see MultipartBody} is sent as
     * `multipart/form-data`; anything else JSON-encodes. `$returnRequest` skips the send and
     * hands back the prepared PSR-7 request for {@see self::executePool()}.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|null|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    private function makeRequest(string $method, string $endpoint, array|JsonSerializable|MultipartBody|null $body = null,
        ?array $query = null, bool $returnRequest = false
    ): RequestInterface|array|null
    {

        return $this->send($method, $endpoint, $body, $query, $returnRequest, true);

    }

    /**
     * `$mayRefresh` is true for the first send of a request and false for the retry after a
     * token refresh, so a 401 on the retry surfaces as a ClientException instead of a second
     * refresh.
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    private function send(string $method, string $endpoint, array|JsonSerializable|MultipartBody|null $body,
        ?array $query, bool $returnRequest, bool $mayRefresh
    ): RequestInterface|array|null
    {

        $request = $this->buildRequest($method, $endpoint, $body, $query);

        if ($returnRequest) {
            return $request;
        }

        $response = self::sendWithRetry($this->transport, $this->retry, $request);
        $this->lastRateLimit = RateLimit::fromResponse($response) ?? $this->lastRateLimit;

        if ($response->getStatusCode() === 401 && $mayRefresh && $this->credentials !== null) {
            $this->refreshCredentials();

            return $this->send($method, $endpoint, $body, $query, false, false);
        }

        return self::decodeResponse($request, $response);

    }

    private function buildRequest(string $method, string $endpoint, array|JsonSerializable|MultipartBody|null $body,
        ?array $query
    ): RequestInterface
    {

        $uri = str_starts_with($endpoint, 'http') ? $endpoint : self::API_BASE_URL . $endpoint;
        if ($query) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($query);
        }

        $request = $this->transport->requestFactory()
            ->createRequest($method, $uri)
            ->withHeader('Authorization', $this->authorization)
            ->withHeader('Accept', self::CONTENT_TYPE)
            ->withHeader('revision', self::API_REVISION)
            ->withHeader('User-Agent', self::USER_AGENT);

        if ($body instanceof MultipartBody) {
            $boundary = MultipartBody::boundary();

            return $request
                ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
                ->withBody($this->transport->streamFactory()->createStream($body->encode($boundary)));
        }

        $request = $request->withHeader('Content-Type', self::CONTENT_TYPE);

        return $body === null
            ? $request
            : $request->withBody($this->transport->streamFactory()->createStream(json_encode($body)));

    }

    /**
     * Sends with the retry policy applied: 429 / 503 and network failures are resent after
     * the policy's delay until it gives up, at which point the last response is returned or
     * the transport failure surfaces as a ConnectionException.
     *
     * @throws ConnectionException
     */
    private static function sendWithRetry(Transport $transport, RetryPolicy $retry, RequestInterface $request): ResponseInterface
    {

        for ($attempt = 1; ; $attempt++) {

            try {
                $response = $transport->send($request);
            } catch (ClientExceptionInterface $e) {
                $delay = $retry->delayForException($e, $attempt);
                if ($delay === null) {
                    throw new ConnectionException($e);
                }
                $retry->sleep($delay);
                self::rewind($request);
                continue;
            }

            $delay = $retry->delayForResponse($response, $attempt);
            if ($delay === null) {
                return $response;
            }

            $retry->sleep($delay);
            self::rewind($request);

        }

    }

    private static function rewind(RequestInterface $request): void
    {

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

    }

    /**
     * Maps status to exception or decoded, hydrated body.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|null
     * @throws ClientException
     * @throws ServerException
     */
    private static function decodeResponse(RequestInterface $request, ResponseInterface $response): ?array
    {

        $status = $response->getStatusCode();

        if ($status >= 500) {
            throw new ServerException($response);
        }
        if ($status >= 400) {
            throw new ClientException($request, $response);
        }

        $contents = (string)$response->getBody();

        if ($contents === '') {
            return null;
        }

        return self::hydrateResponse(json_decode($contents, true));

    }

    /**
     * Hydrates a decoded JSON:API response. Converts resource objects to typed classes and
     * pagination links to PaginationLinks. `links` is always set, null when the response
     * carried none, so `$result['links']?->next` is safe on every endpoint.
     *
     * Compound documents are resolved: when the request used `include=`, every relationship
     * identifier that matches an entry of the top-level `included` array hydrates to that
     * full resource (attributes, meta and its own relationships), so
     * `$resource->getRelationship('tags')->data[0]->name` works. `included` itself is kept
     * on the result as a hydrated list for callers that want the flat view.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks, included?: IdentifiableResource[]}
     */
    public static function hydrateResponse(array $result): array
    {

        $included = [];
        if (is_array($result['included'] ?? null)) {
            foreach ($result['included'] as $entry) {
                if (is_array($entry) && isset($entry['type'], $entry['id'])) {
                    $included[self::includedKey((string)$entry['type'], (string)$entry['id'])] = $entry;
                }
            }
        }

        if (isset($result['data'])) {
            $result['data'] = array_is_list($result['data'])
                ? array_map(fn($item) => self::hydrateResource($item, $included) ?? $item, $result['data'])
                : self::hydrateResource($result['data'], $included) ?? $result['data'];
        }

        if ($included) {
            $result['included'] = array_values(array_filter(array_map(
                fn(array $entry) => self::hydrateResource($entry, $included),
                array_values($included),
            )));
        }

        $result['links'] = isset($result['links']) ? PaginationLinks::from($result['links']) : null;

        return $result;

    }

    private static function includedKey(string $type, string $id): string
    {

        return $type . "\0" . $id;

    }

    /**
     * @param array<string, array> $included  raw `included` entries keyed by {@see self::includedKey()}
     * @param array<string, true>  $resolving keys currently being hydrated up the call stack; a
     *                                        relationship pointing back at one of them stays an
     *                                        identifier so cyclic includes cannot recurse forever
     */
    private static function hydrateResource(array $item, array $included = [], array $resolving = []): ?IdentifiableResource
    {

        $resourceClass = TypeRegistry::resolve($item['type'] ?? '');
        if (!$resourceClass) {
            return null;
        }

        $key = isset($item['type'], $item['id']) ? self::includedKey((string)$item['type'], (string)$item['id']) : null;

        // An identifier (no attributes) that the response also carries in `included`: hydrate
        // the full entry instead, keeping any meta the identifier itself had (e.g. relationship_id).
        if ($key !== null && !isset($item['attributes']) && isset($included[$key]) && !isset($resolving[$key])) {
            $full = $included[$key];
            if (is_array($item['meta'] ?? null)) {
                $full['meta'] = ($item['meta']) + (is_array($full['meta'] ?? null) ? $full['meta'] : []);
            }
            $item = $full;
        }
        if ($key !== null) {
            $resolving[$key] = true;
        }

        /** @var IdentifiableResource $resource */
        $resource = $resourceClass::from($item['attributes'] ?? [], $item['id'] ?? null);

        if (is_array($item['meta'] ?? null)) {
            foreach ($item['meta'] as $name => $value) {
                $resource->addMeta((string)$name, $value);
            }
        }

        if (is_array($item['relationships'] ?? null)) {
            foreach ($item['relationships'] as $name => $rel) {
                if (!is_array($rel)) {
                    continue;
                }
                $links = isset($rel['links']) ? Resources\Shared\RelationshipLinks::from($rel['links']) : null;
                if (!array_key_exists('data', $rel)) {
                    // Links-only relationship (no `include`, or the API never inlines data here).
                    $resource->addRelationship($name, new Resources\Shared\Relationship([], $links, false));
                    continue;
                }
                $data = $rel['data'];
                if ($data === null) {
                    $resource->addRelationship($name, new Resources\Shared\Relationship(null, $links));
                    continue;
                }
                $data = array_is_list($data)
                    ? array_map(fn($entry) => is_array($entry) ? (self::hydrateResource($entry, $included, $resolving) ?? $entry) : $entry, $data)
                    : (self::hydrateResource($data, $included, $resolving) ?? $data);
                $resource->addRelationship($name, new Resources\Shared\Relationship($data, $links));
            }
        }

        return $resource;

    }

    /**
     * Executes prepared requests concurrently through the transport. Each entry in the
     * returned array is the unwrapped `data` from the hydrated JSON:API response (single
     * resource or array of resources, depending on the endpoint), null for empty bodies, or
     * a translated Klaviyo exception when that request was rejected. Order matches the input
     * request order. Callers that don't tolerate partial failure should pass the result
     * through assertNoExceptions().
     *
     * Retries follow the same {@see RetryPolicy} as single sends, run as further rounds:
     * after a round completes, every request that got a retryable status or network error is
     * resent after the longest requested delay. Retry rounds run one request at a time: the
     * usual cause is a 429 from exceeding the endpoint's burst limit, and resending the failed
     * requests concurrently would trip it again (observed live against `/api/accounts`, whose
     * burst limit is 1/s: five concurrent sends failed one request outright after ten 429 rounds;
     * with sequential retry rounds all ten succeed). Only the failed requests are kept between rounds, so the memory
     * contract below holds.
     *
     * Choose `$concurrency` at or below the endpoint's burst limit (see
     * {@see RateLimit::burstLimit()} on a previous response); anything above it only produces
     * 429s and retry sleeps.
     *
     * Accepts an iterable so callers can pass a generator. With a generator, request objects
     * (which can be huge for bulk subscribe / bulk import JSON bodies) are constructed lazily
     * as the transport consumes them, capping peak memory at O(concurrency × body) rather than
     * O(N × body), which matters for large bulk syncs.
     *
     * @param iterable<int, RequestInterface> $requests
     * @return array<int, IdentifiableResource|IdentifiableResource[]|null|ClientException|ServerException|ConnectionException>
     */
    public function executePool(iterable $requests, int $concurrency = 5): array
    {

        $results = [];
        $pending = $requests;

        for ($attempt = 1; ; $attempt++) {

            $retryQueue = [];
            $longestDelay = 0.0;

            $this->transport->sendConcurrently(
                $pending,
                $attempt === 1 ? $concurrency : 1,
                function ($key, RequestInterface $request, ResponseInterface $response) use (&$results, &$retryQueue, &$longestDelay, $attempt) {
                    $delay = $this->retry->delayForResponse($response, $attempt);
                    if ($delay !== null) {
                        $retryQueue[$key] = $request;
                        $longestDelay = max($longestDelay, $delay);

                        return;
                    }
                    try {
                        $results[$key] = self::decodeResponse($request, $response)['data'] ?? null;
                    } catch (ClientException|ServerException $e) {
                        $results[$key] = $e;
                    }
                },
                function ($key, RequestInterface $request, Throwable $reason) use (&$results, &$retryQueue, &$longestDelay, $attempt) {
                    $delay = $this->retry->delayForException($reason, $attempt);
                    if ($delay !== null) {
                        $retryQueue[$key] = $request;
                        $longestDelay = max($longestDelay, $delay);

                        return;
                    }
                    $results[$key] = new ConnectionException($reason);
                },
            );

            if (!$retryQueue) {
                break;
            }

            $this->retry->sleep($longestDelay);
            foreach ($retryQueue as $request) {
                self::rewind($request);
            }
            $pending = $retryQueue;

        }

        ksort($results);

        return array_values($results);

    }

    /**
     * Auto-pagination over any listing that takes a `next` URL. `$fetch` receives null for the
     * first page and `links.next` afterwards; see {@see Paginator}.
     *
     * ```php
     * foreach ($client->paginate(fn(?string $next) => $client->lists->profiles($listId, $query, next: $next))->items() as $profile) { … }
     * ```
     *
     * @template T of IdentifiableResource
     * @param callable(?string): array{data: T[], links: ?PaginationLinks} $fetch
     * @return Paginator<T>
     */
    public function paginate(callable $fetch): Paginator
    {

        return new Paginator($fetch);

    }

    /**
     * Convenience wrapper around {@see self::executePool()} for the common case where the
     * caller has a list of inputs (jobs, profile chunks, etc.) and a function that turns
     * one input into a request. Inputs are iterated lazily and request objects are only
     * built as the transport consumes them, so peak memory stays at O(concurrency × body) —
     * the same memory contract as passing your own generator to executePool.
     *
     * @template T
     * @param iterable<T>                    $items
     * @param callable(T): RequestInterface  $toRequest
     */
    public function executePoolLazy(iterable $items, callable $toRequest, int $concurrency = 5): array
    {

        $requests = (static function () use ($items, $toRequest) {
            foreach ($items as $item) {
                yield $toRequest($item);
            }
        })();

        return $this->executePool($requests, $concurrency);

    }

    /**
     * Re-raises the first exception in a pool result, if any. Used by callers that want
     * all-or-nothing semantics rather than per-request partial-success handling.
     *
     * @param array<int, array|null|ClientException|ServerException|ConnectionException> $results
     *
     * @throws ClientException|ServerException|ConnectionException
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function assertNoExceptions(array $results): void
    {

        foreach ($results as $r) {
            if ($r instanceof BaseException) {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw $r;
            }
        }

    }

    // endregion

}
