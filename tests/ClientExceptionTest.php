<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\KlaviyoError;
use PHPUnit\Framework\TestCase;

/**
 * ClientException turns Klaviyo's JSON:API `errors` array into KlaviyoError objects. Callers
 * branch on `code`, `meta` (duplicate_profile_id on 409) and the JSON pointer, so each of
 * those has to survive parsing, and a body that is not JSON:API must not blow up.
 */
class ClientExceptionTest extends TestCase
{

    private static function exception(int $status, mixed $body): ClientException
    {

        return new ClientException(
            new Request('POST', 'https://a.klaviyo.com/api/profiles'),
            new Response($status, [], is_string($body) ? $body : json_encode($body)),
        );

    }

    public function testParsesEveryErrorField(): void
    {

        $e = self::exception(400, ['errors' => [[
            'id'     => '360c1a11-cc3b-4f6c-b1a8-3cfbacd77c2f',
            'status' => 400,
            'code'   => 'invalid',
            'title'  => 'Invalid input.',
            'detail' => 'Phone number is valid but is not in a supported region for this account.',
            'source' => ['pointer' => '/data/attributes/profiles/data/2/attributes/phone_number'],
            'links'  => [],
            'meta'   => [],
        ], [
            'code'   => 'invalid',
            'detail' => 'Unknown field.',
            'source' => ['parameter' => 'fields[profile]'],
        ]]]);

        self::assertSame(400, $e->getHttpStatus());
        self::assertStringContainsString('not in a supported region', $e->getMessage());
        self::assertCount(2, $e->getErrors());
        self::assertContainsOnlyInstancesOf(KlaviyoError::class, $e->getErrors());

        $first = $e->getFirstError();
        self::assertSame('360c1a11-cc3b-4f6c-b1a8-3cfbacd77c2f', $first->id);
        self::assertSame(400, $first->status);
        self::assertSame('invalid', $first->code);
        self::assertSame('Invalid input.', $first->title);
        self::assertSame('/data/attributes/profiles/data/2/attributes/phone_number', $first->pointer);
        self::assertNull($first->parameter);
        self::assertSame(['data', 'attributes', 'profiles', 'data', '2', 'attributes', 'phone_number'], $first->pointerSegments());
        self::assertSame(2, $first->indexIn('/data/attributes/profiles/data'));
        self::assertNull($first->indexIn('/data/attributes/events/data'));
        self::assertSame([], $first->meta);
        self::assertSame('invalid', $first->raw['code']);
        self::assertSame('400 invalid Phone number is valid but is not in a supported region for this account. at /data/attributes/profiles/data/2/attributes/phone_number', (string)$first);

        $second = $e->getErrors()[1];
        self::assertSame('fields[profile]', $second->parameter);
        self::assertNull($second->pointer);
        self::assertNull($second->id);
        self::assertSame([], $second->pointerSegments());

        self::assertCount(2, $e->getErrorsWithCode('invalid'));
        self::assertSame([], $e->getErrorsWithCode('not_found'));
        self::assertSame('Unknown field.', $e->getRawErrors()[1]['detail']);

    }

    public function testDuplicateProfileMetaIsExposed(): void
    {

        $e = self::exception(409, ['errors' => [[
            'id'     => 'x',
            'status' => 409,
            'code'   => 'duplicate_profile',
            'title'  => 'Conflict.',
            'detail' => 'A profile already exists with one of these identifiers.',
            'source' => ['pointer' => '/data/attributes'],
            'meta'   => ['duplicate_profile_id' => '01GDDKASAP8TKDDA2GRZDSVP4H'],
        ]]]);

        self::assertSame('01GDDKASAP8TKDDA2GRZDSVP4H', $e->getFirstError()->meta['duplicate_profile_id']);
        self::assertSame('duplicate_profile', $e->getFirstError()->code);

    }

    public function testTitleIsUsedWhenDetailIsMissing(): void
    {

        $e = self::exception(403, ['errors' => [['status' => 403, 'code' => 'forbidden', 'title' => 'You must have Advanced KDP enabled to use this endpoint.']]]);

        self::assertSame('Klaviyo client error (HTTP 403): You must have Advanced KDP enabled to use this endpoint.', $e->getMessage());
        self::assertNull($e->getFirstError()->detail);

    }

    public function testNonJsonApiBodyYieldsNoErrors(): void
    {

        foreach (['<html>nope</html>', '', '{"message":"plain"}', '{"errors":"not-a-list"}', '{"errors":[1,"two"]}'] as $body) {
            $e = self::exception(400, $body);
            self::assertSame([], $e->getErrors(), $body);
            self::assertNull($e->getFirstError(), $body);
            self::assertSame('Klaviyo client error (HTTP 400): Failed to parse response.', $e->getMessage(), $body);
        }

        self::assertNull(self::exception(400, '<html>nope</html>')->getRawErrors());
        self::assertSame([1, 'two'], self::exception(400, '{"errors":[1,"two"]}')->getRawErrors());

    }

}
