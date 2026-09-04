<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\OAuthCredentials;
use nickdnk\Klaviyo\TokenExchange;
use nickdnk\Klaviyo\Resources\Response\Profile as ResponseProfile;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\OAuthScope;
use nickdnk\Klaviyo\Resources\Response\Profile;

class APIClientTest extends TestCase
{

    private static function tokenResponse(string $accessToken, string $refreshToken): Response
    {

        return new Response(200, [], json_encode([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => 3600,
            'token_type'    => 'Bearer',
            'scope'         => 'profiles:read profiles:write',
        ]));

    }

    public function testApiRequest_on401_refreshesOnce_doesNotLoop(): void
    {

        $mock = new MockHandler([
            new Response(401, [], json_encode(['errors' => [['detail' => 'token expired']]])),
            self::tokenResponse('fresh', 'rt2'),
            new Response(401, [], json_encode(['errors' => [['detail' => 'still expired']]])),
        ]);
        $stack = HandlerStack::create($mock);

        $refreshCalls = 0;
        $threw = null;

        try {
            $client = APIClient::withOAuth(
                new OAuthCredentials('stale_token', 'rt1', time() + 3600), 'cid', 'sec',
                function (OAuthCredentials $c, TokenExchange $exchange) use (&$refreshCalls) {
                    $refreshCalls++;
                    return $exchange($c);
                },
                GuzzleTransport::fromHandlerStack($stack)
            );
            $client->profiles->get('prof_1');
        } catch (ClientException $e) {
            $threw = $e;
        }

        self::assertInstanceOf(ClientException::class, $threw, 'Second 401 should surface.');
        self::assertSame(1, $refreshCalls, 'Refresh callback must fire exactly once.');
        self::assertSame(0, $mock->count(), 'All canned responses should have been consumed.');

    }

    public function testApiRequest_on401_firesRefreshCallbackBeforeRetrying(): void
    {

        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(401, [], json_encode(['errors' => [['detail' => 'token expired']]])),
            self::tokenResponse('fresh', 'rt2'),
            new Response(200, [], json_encode([
                'data' => [
                    'type'       => 'profile',
                    'id'         => 'prof_1',
                    'attributes' => ['email' => 'u@x.test'],
                ],
            ])),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($sent));

        $received = null;
        $current = null;
        $initial = new OAuthCredentials('stale_token', 'rt1', time() + 3600);

        $client = APIClient::withOAuth(
            $initial, 'cid', 'sec',
            function (OAuthCredentials $c, TokenExchange $exchange) use (&$received, &$current) {
                $current = $c;
                return $received = $exchange($c);
            },
            GuzzleTransport::fromHandlerStack($stack)
        );
        $result = $client->profiles->get('prof_1');

        self::assertSame($initial, $current, 'Callback receives the credentials the client currently holds.');
        self::assertInstanceOf(OAuthCredentials::class, $received, 'Refresh callback must fire before the retry.');
        self::assertSame('fresh', $received->accessToken);
        self::assertSame('rt2', $received->refreshToken);
        self::assertSame([\nickdnk\Klaviyo\OAuthScope::profilesRead, \nickdnk\Klaviyo\OAuthScope::profilesWrite], $received->scopes());
        self::assertSame($received, $client->getCredentials(), 'Client keeps the credentials it handed out.');
        self::assertInstanceOf(ResponseProfile::class, $result);
        self::assertSame('prof_1', $result->id);

        self::assertCount(3, $sent);
        self::assertSame('Bearer stale_token', $sent[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('https://a.klaviyo.com/oauth/token', (string)$sent[1]['request']->getUri());
        self::assertSame('grant_type=refresh_token&refresh_token=rt1', (string)$sent[1]['request']->getBody());
        self::assertSame('Bearer fresh', $sent[2]['request']->getHeaderLine('Authorization'), 'The retry is rebuilt with the refreshed token.');

    }

    public function testApiRequest_on401_withoutOAuth_isFinal(): void
    {

        $mock = new MockHandler([
            new Response(401, [], json_encode(['errors' => [['detail' => 'token expired']]])),
        ]);

        $this->expectException(ClientException::class);

        APIClient::withAccessToken('stale_token', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)))->profiles->get('prof_1');

    }

    public function testApiRequest_on401_refreshFailure_propagatesOAuthException(): void
    {

        $mock = new MockHandler([
            new Response(401, [], json_encode(['errors' => [['detail' => 'token expired']]])),
            new Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'revoked'])),
        ]);

        $initial = new OAuthCredentials('stale_token', 'rt1', time() + 3600);
        $threw = null;

        $client = APIClient::withOAuth(
            $initial, 'cid', 'sec',
            fn(OAuthCredentials $c, TokenExchange $exchange) => $exchange($c),
            GuzzleTransport::fromHandlerStack(HandlerStack::create($mock))
        );

        try {
            $client->profiles->get('prof_1');
        } catch (OAuthException $e) {
            $threw = $e;
        }

        self::assertInstanceOf(OAuthException::class, $threw);
        self::assertTrue($threw->isInvalidGrant());
        self::assertSame($initial, $client->getCredentials(), 'A failed exchange leaves the client on its old credentials.');
        self::assertSame(0, $mock->count());

    }

    public function testApiRequest_on401_callbackMayReturnStoredCredentialsWithoutExchanging(): void
    {

        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(401, [], json_encode(['errors' => [['detail' => 'token expired']]])),
            new Response(200, [], json_encode(['data' => ['type' => 'profile', 'id' => 'prof_1', 'attributes' => []]])),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($sent));

        $stored = new OAuthCredentials('from_other_process', 'rt9', time() + 3600);

        $client = APIClient::withOAuth(
            new OAuthCredentials('stale_token', 'rt1', time() + 3600), 'cid', 'sec',
            fn(OAuthCredentials $c, TokenExchange $exchange) => $stored,
            GuzzleTransport::fromHandlerStack($stack)
        );
        $client->profiles->get('prof_1');

        self::assertCount(2, $sent, 'No token request when the callback returns stored credentials.');
        self::assertSame('Bearer from_other_process', $sent[1]['request']->getHeaderLine('Authorization'));
        self::assertSame($stored, $client->getCredentials());

    }

    public function testRefreshCredentials(): void
    {

        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            self::tokenResponse('fresh', 'rt2'),
            new Response(200, [], json_encode(['data' => ['type' => 'profile', 'id' => 'prof_1', 'attributes' => []]])),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($sent));

        $received = [];
        $initial = new OAuthCredentials('stale_token', 'rt1', time() - 10);
        self::assertTrue($initial->isExpired());

        $client = APIClient::withOAuth($initial, 'cid', 'sec', function (OAuthCredentials $c, TokenExchange $exchange) use (&$received) {
            return $received[] = $exchange($c);
        }, GuzzleTransport::fromHandlerStack($stack));

        $fresh = $client->refreshCredentials();
        self::assertSame('fresh', $fresh->accessToken);
        self::assertFalse($fresh->isExpired());
        self::assertSame([$fresh], $received);
        $client->profiles->get('prof_1');

        self::assertSame('https://a.klaviyo.com/oauth/token', (string)$sent[0]['request']->getUri());
        self::assertSame('Bearer fresh', $sent[1]['request']->getHeaderLine('Authorization'));

    }

    public function testRefreshCredentials_requiresOAuthClient(): void
    {

        $client = APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create(new MockHandler([]))));

        self::assertNull($client->getCredentials());
        $this->expectException(LogicException::class);
        $client->refreshCredentials();

    }

    public function testApiRequest_on429_respectsRetryAfterAndRetries(): void
    {

        $stack = HandlerStack::create(new MockHandler([
            new Response(429, ['Retry-After' => '0'], json_encode(['errors' => [['detail' => 'rate limited']]])),
            new Response(200, [], json_encode([
                'data' => [
                    'type'       => 'profile',
                    'id'         => 'prof_1',
                    'attributes' => ['email' => 'u@x.test'],
                ],
            ])),
        ]));

        $result = APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack($stack))->profiles->get('prof_1');

        self::assertInstanceOf(ResponseProfile::class, $result, '429 should have been retried and yielded the 200 response.');
        self::assertSame('prof_1', $result->id);

    }

    /** Retry-After must be honoured verbatim, not compounded with the backoff multiplier. */
    public function testApiRequest_on429_sleepsForRetryAfterDuration(): void
    {

        $stack = HandlerStack::create(new MockHandler([
            new Response(429, ['Retry-After' => '1'], json_encode(['errors' => [['detail' => 'rate limited']]])),
            new Response(429, ['Retry-After' => '1'], json_encode(['errors' => [['detail' => 'rate limited']]])),
            new Response(200, [], json_encode([
                'data' => [
                    'type'       => 'profile',
                    'id'         => 'prof_1',
                    'attributes' => ['email' => 'u@x.test'],
                ],
            ])),
        ]));

        $start = microtime(true);
        $result = APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack($stack))->profiles->get('prof_1');
        $elapsed = microtime(true) - $start;

        self::assertInstanceOf(ResponseProfile::class, $result);
        self::assertSame('prof_1', $result->id);
        self::assertGreaterThanOrEqual(
            1.9,
            $elapsed,
            "Should have slept ~2s total (2 x Retry-After: 1) but elapsed was {$elapsed}s."
        );
        self::assertLessThan(
            4.0,
            $elapsed,
            "Should not have slept much longer than ~2s but elapsed was {$elapsed}s."
        );

    }

    public function testOAuthExceptionExposesInvalidGrantFromErrorBody(): void
    {

        $stack = HandlerStack::create(new MockHandler([
            new Response(400, [], json_encode([
                'error'             => 'invalid_grant',
                'error_description' => 'Code expired.',
            ])),
        ]));

        $threw = null;
        try {
            APIClient::exchangeCodeForToken(
                'cid', 'csecret', 'code', 'verifier', 'https://example.com/cb', GuzzleTransport::fromHandlerStack($stack)
            );
        } catch (OAuthException $e) {
            $threw = $e;
        }

        self::assertInstanceOf(OAuthException::class, $threw);
        self::assertSame('invalid_grant', $threw->getErrorCode());
        self::assertTrue($threw->isInvalidGrant());

    }

    private MockHandler $mock;

    private function client(Response ...$responses): APIClient
    {

        $this->mock = new MockHandler($responses);

        return APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack(HandlerStack::create($this->mock)), new RetryPolicy(jitterFactor: 0, sleep: fn() => null));

    }



    public function testOAuthCredentialsRejectIncompleteTokenResponsesAndHandleMissingScope(): void
    {

        foreach ([[], ['access_token' => 'a'], ['access_token' => 'a', 'refresh_token' => 'r'], ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 'soon'], ['access_token' => '', 'refresh_token' => 'r', 'expires_in' => 60]] as $bad) {
            try {
                OAuthCredentials::fromTokenResponse($bad);
                self::fail('expected InvalidArgumentException for ' . json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }

        $c = OAuthCredentials::fromTokenResponse(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => '3600', 'scope' => ''], 1000);
        self::assertSame(4600, $c->expiresAt);
        self::assertNull($c->scope, 'an empty scope string is treated as unknown');
        self::assertSame([], $c->scopes());
        self::assertSame([OAuthScope::profilesRead], (new OAuthCredentials('a', 'r', 0, 'profiles:read  made:up'))->scopes(), 'unknown scopes are skipped');

    }


    public function testOAuthHelpers(): void
    {

        $verifier = APIClient::generateCodeVerifier();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{86}$/', $verifier, 'base64url of 64 random bytes without padding');
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), APIClient::generateCodeChallenge($verifier));

        $url = APIClient::getOAuthLink('cid', 'st4te', $verifier, [OAuthScope::profilesRead, OAuthScope::eventsWrite], 'https://example.com/cb');
        self::assertStringStartsWith('https://www.klaviyo.com/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame(['response_type' => 'code', 'client_id' => 'cid', 'redirect_uri' => 'https://example.com/cb', 'scope' => 'profiles:read events:write', 'state' => 'st4te', 'code_challenge_method' => 'S256', 'code_challenge' => APIClient::generateCodeChallenge($verifier)], $q);

    }


    public function testRevokeTokenAndTokenEndpointServerError(): void
    {

        $mock = new MockHandler([new Response(200, [], ''), new Response(500, [], 'boom')]);
        $transport = GuzzleTransport::fromHandlerStack(HandlerStack::create($mock));

        APIClient::revokeToken('cid', 'sec', 'refresh-token', $transport);
        self::assertSame('https://a.klaviyo.com/oauth/revoke', (string)$mock->getLastRequest()->getUri());
        $mock->getLastRequest()->getBody()->rewind();
        self::assertSame('token=refresh-token&token_type_hint=refresh_token', (string)$mock->getLastRequest()->getBody());
        self::assertSame('Basic ' . base64_encode('cid:sec'), $mock->getLastRequest()->getHeaderLine('Authorization'));

        $this->expectException(ServerException::class);
        APIClient::refreshAccessToken('cid', 'sec', 'rt', $transport);

    }


    public function testUpdateAccessToken(): void
    {

        $mock = new MockHandler([new Response(200, [], json_encode(['data' => []]))]);
        $client = APIClient::withAccessToken('stale', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));
        $client->updateAccessToken('fresh');
        $client->lists->list();
        self::assertSame('Bearer fresh', $mock->getLastRequest()->getHeaderLine('Authorization'));

    }

    public function testUpdateAccessToken_rejectsOAuthAndApiKeyClients(): void
    {

        $transport = GuzzleTransport::fromHandlerStack(HandlerStack::create(new MockHandler([])));

        $oauth = APIClient::withOAuth(new OAuthCredentials('a', 'r', 0), 'cid', 'sec', fn($c, $x) => $x($c), $transport);
        try {
            $oauth->updateAccessToken('x');
            self::fail('OAuth client must reject updateAccessToken().');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        APIClient::withApiKey('pk_x', $transport)->updateAccessToken('x');

    }


    public function testDefaultTransportCanBeSetAndCleared(): void
    {

        $mock = new MockHandler([new Response(200, [], json_encode(['data' => []]))]);
        APIClient::setDefaultTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));
        try {
            APIClient::withAccessToken('t')->lists->list();
            self::assertSame('/api/lists', $mock->getLastRequest()->getUri()->getPath());
        } finally {
            APIClient::setDefaultTransport(null);
        }

        // With the default cleared and Guzzle installed, a client still gets a transport of its own.
        $client = APIClient::withAccessToken('t');
        self::assertInstanceOf(APIClient::class, $client);
        APIClient::setDefaultTransport(null);

    }


    public function testAssertNoExceptionsRethrowsTheFirstFailure(): void
    {

        APIClient::assertNoExceptions([null, [], new Profile('p')]);
        $this->expectException(ConnectionException::class);
        APIClient::assertNoExceptions([new Profile('p'), new ConnectionException(new RuntimeException('down')), new ServerException(new Response(500))]);

    }

    // endregion

    // region services

}
