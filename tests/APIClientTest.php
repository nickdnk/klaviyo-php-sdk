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
use nickdnk\Klaviyo\Resources\Response\Profile as ResponseProfile;
use PHPUnit\Framework\TestCase;

/**
 * Tests the two pieces of APIClient that have non-obvious behavior worth regression-
 * proofing:
 *   1. On 401 an OAuth client refreshes its tokens exactly once, hands the new credentials to
 *      the refresh callback and retries the request — without looping if the refreshed token
 *      is also rejected.
 *   2. A 4xx from the OAuth endpoint is turned into an OAuthException whose
 *      `isInvalidGrant()` flag lets callers detect a revoked or expired refresh token.
 *
 * Everything else APIClient does (wire format, hydration, exception mapping) is either
 * already covered by KlaviyoResourceTest or is shallow enough that a mocked-Guzzle test
 * would mostly just re-state the implementation.
 */
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

    /**
     * When the API returns 401, an OAuth client refreshes its tokens and retries the request.
     * If the retry is ALSO 401 (e.g. the refresh itself produced a bad token), it must surface
     * as a ClientException rather than loop back into another refresh.
     *
     * We queue 401, token response, 401 so the retry hits the guard. Asserting
     * `$refreshCalls === 1` proves the guard held — without it, the second 401 would trigger a
     * second refresh and we'd see 2+ calls (or MockHandler would exhaust and throw a different
     * error).
     */
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
            APIClient::withTransport(GuzzleTransport::fromHandlerStack($stack), function () use (&$refreshCalls) {
                $client = APIClient::withOAuth(
                    new OAuthCredentials('stale_token', 'rt1', time() + 3600), 'cid', 'sec',
                    function (OAuthCredentials $c) use (&$refreshCalls) {
                        $refreshCalls++;
                    }
                );
                $client->profiles->get('prof_1');
            });
        } catch (ClientException $e) {
            $threw = $e;
        }

        self::assertInstanceOf(ClientException::class, $threw, 'Second 401 should surface.');
        self::assertSame(1, $refreshCalls, 'Refresh callback must fire exactly once.');
        self::assertSame(0, $mock->count(), 'All canned responses should have been consumed.');

    }

    /**
     * Happy-path companion to the guard test above: on a 401 followed by a 200, the client
     * must refresh through the token endpoint, hand the rotated credentials to the callback
     * (so the application can persist them) and retry with the new access token. If the retry
     * succeeds but the callback never fires, storage never learns about the rotated tokens and
     * the next request restarts the refresh dance.
     */
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
        $client = null;

        $result = APIClient::withTransport(GuzzleTransport::fromHandlerStack($stack), function () use (&$received, &$client) {
            $client = APIClient::withOAuth(
                new OAuthCredentials('stale_token', 'rt1', time() + 3600), 'cid', 'sec',
                function (OAuthCredentials $c) use (&$received) {
                    $received = $c;
                }
            );
            return $client->profiles->get('prof_1');
        });

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

    /**
     * A bearer-token client built with the constructor has no refresh token, so a 401 is final
     * and surfaces straight away as a ClientException.
     */
    public function testApiRequest_on401_withoutOAuth_isFinal(): void
    {

        $mock = new MockHandler([
            new Response(401, [], json_encode(['errors' => [['detail' => 'token expired']]])),
        ]);

        $this->expectException(ClientException::class);

        APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), function () {
            (new APIClient('stale_token'))->profiles->get('prof_1');
        });

    }

    /**
     * A refresh that fails with `invalid_grant` (revoked or expired refresh token) propagates
     * out of the API call as an OAuthException, so the application can mark the connection as
     * needing re-authorization. The callback must not fire for a failed refresh.
     */
    public function testApiRequest_on401_refreshFailure_propagatesOAuthException(): void
    {

        $mock = new MockHandler([
            new Response(401, [], json_encode(['errors' => [['detail' => 'token expired']]])),
            new Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'revoked'])),
        ]);

        $callbackFired = false;
        $threw = null;

        try {
            APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), function () use (&$callbackFired) {
                APIClient::withOAuth(
                    new OAuthCredentials('stale_token', 'rt1', time() + 3600), 'cid', 'sec',
                    function () use (&$callbackFired) {
                        $callbackFired = true;
                    }
                )->profiles->get('prof_1');
            });
        } catch (OAuthException $e) {
            $threw = $e;
        }

        self::assertInstanceOf(OAuthException::class, $threw);
        self::assertTrue($threw->isInvalidGrant());
        self::assertFalse($callbackFired, 'Callback must only receive successfully refreshed credentials.');
        self::assertSame(0, $mock->count());

    }

    /**
     * refreshCredentials() is the proactive counterpart to the 401 path: same token request,
     * same callback, and the client uses the new token from then on. setCredentials() swaps
     * in tokens obtained elsewhere without touching the callback.
     */
    public function testRefreshCredentials_andSetCredentials(): void
    {

        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            self::tokenResponse('fresh', 'rt2'),
            new Response(200, [], json_encode(['data' => ['type' => 'profile', 'id' => 'prof_1', 'attributes' => []]])),
            new Response(200, [], json_encode(['data' => ['type' => 'profile', 'id' => 'prof_1', 'attributes' => []]])),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($sent));

        $received = [];
        $initial = new OAuthCredentials('stale_token', 'rt1', time() - 10);
        self::assertTrue($initial->isExpired());

        APIClient::withTransport(GuzzleTransport::fromHandlerStack($stack), function () use (&$received, $initial) {
            $client = APIClient::withOAuth($initial, 'cid', 'sec', function (OAuthCredentials $c) use (&$received) {
                $received[] = $c;
            });

            $fresh = $client->refreshCredentials();
            self::assertSame('fresh', $fresh->accessToken);
            self::assertFalse($fresh->isExpired());
            self::assertSame([$fresh], $received);
            $client->profiles->get('prof_1');

            $client->setCredentials(new OAuthCredentials('elsewhere', 'rt3', time() + 3600));
            $client->profiles->get('prof_1');
            self::assertCount(1, $received, 'setCredentials() must not invoke the callback.');
        });

        self::assertSame('https://a.klaviyo.com/oauth/token', (string)$sent[0]['request']->getUri());
        self::assertSame('Bearer fresh', $sent[1]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer elsewhere', $sent[2]['request']->getHeaderLine('Authorization'));

    }

    public function testRefreshCredentials_requiresOAuthClient(): void
    {

        $client = new APIClient('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create(new MockHandler([]))));

        self::assertNull($client->getCredentials());
        $this->expectException(\LogicException::class);
        $client->refreshCredentials();

    }

    /**
     * Klaviyo enforces a 10 r/s burst / 150 r/min steady limit and replies 429 with a
     * Retry-After header when exceeded. A bulk sync can issue dozens of requests, so we
     * installed guzzle_retry_middleware in the APIClient handler stack to honour that
     * header transparently. This test pins the behaviour: a 429 followed by a 200 must
     * surface as a single successful response, with both canned replies consumed.
     *
     * Retry-After is set to 0 so the middleware sleeps for zero seconds in tests.
     */
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

        $result = APIClient::withTransport(GuzzleTransport::fromHandlerStack($stack), function () {
            $client = new APIClient('tkn');
            return $client->profiles->get('prof_1');
        });

        self::assertInstanceOf(ResponseProfile::class, $result, '429 should have been retried and yielded the 200 response.');
        self::assertSame('prof_1', $result->id);

    }

    /**
     * Companion to the Retry-After=0 test: when Klaviyo sets a non-zero Retry-After,
     * the middleware must actually sleep for that duration between retries (rather than
     * e.g. falling back to the default multiplier or ignoring the header). We queue two
     * consecutive 429s with Retry-After: 1, then a 200, and verify wall-clock elapsed
     * time clears the expected minimum sleep.
     *
     * Upper bound is generous to tolerate CI jitter; the important property is that
     * we're NOT sleeping many seconds longer than asked (which would indicate the
     * default_retry_multiplier is being compounded with the header).
     */
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
        $result = APIClient::withTransport(GuzzleTransport::fromHandlerStack($stack), function () {
            $client = new APIClient('tkn');
            return $client->profiles->get('prof_1');
        });
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

    /**
     * OAuthException parses Klaviyo's `error` field and exposes `isInvalidGrant()`. Callers
     * check that flag to decide whether to mark an integration as deauthorized. If parsing
     * regresses, deauthorization stops happening on expired refresh tokens and integrations
     * silently break.
     */
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
            APIClient::withTransport(GuzzleTransport::fromHandlerStack($stack), fn() => APIClient::exchangeCodeForToken(
                'cid', 'csecret', 'code', 'verifier', 'https://example.com/cb'
            ));
        } catch (OAuthException $e) {
            $threw = $e;
        }

        self::assertInstanceOf(OAuthException::class, $threw);
        self::assertSame('invalid_grant', $threw->getErrorCode());
        self::assertTrue($threw->isInvalidGrant());

    }

}
