<?php


namespace nickdnk\Klaviyo\Tests;

use nickdnk\Klaviyo\Resources\Request\UpdateTrackingSetting;
use nickdnk\Klaviyo\Resources\Request\UpdateWebhook;
use nickdnk\Klaviyo\Resources\Shared\Explicit;
use PHPUnit\Framework\TestCase;

/**
 * Request serialisation drops nulls and empty arrays; Explicit markers are the escape hatch for
 * PATCH endpoints that need an explicit null / [] / {} to clear a value.
 */
class ExplicitTest extends TestCase
{

    public function testMarkersSurviveSerialisationWhilePlainNullsAreDropped(): void
    {

        $u = new UpdateWebhook('w1');
        $u->name = 'renamed';
        $u->description = Explicit::null();
        $u->secret_key = null;

        $json = json_decode(json_encode($u), true);

        self::assertSame(['name' => 'renamed', 'description' => null], $json['attributes']);
        self::assertArrayNotHasKey('secret_key', $json['attributes']);

    }

    public function testEmptyListAndEmptyObject(): void
    {

        $u = new UpdateTrackingSetting('WBhXHN');
        $u->custom_parameters = Explicit::emptyList();
        $u->utm_source = Explicit::emptyObject();
        $u->utm_medium = [];

        $encoded = json_encode($u);
        $json = json_decode($encoded, true);

        self::assertSame([], $json['attributes']['custom_parameters']);
        self::assertStringContainsString('"custom_parameters":[]', $encoded);
        self::assertStringContainsString('"utm_source":{}', $encoded);
        self::assertArrayNotHasKey('utm_medium', $json['attributes'], 'a plain empty array is still dropped');

    }

    public function testMarkersWorkInsideNestedArrays(): void
    {

        $u = new UpdateTrackingSetting('WBhXHN');
        $u->utm_term = ['type' => 'static', 'value' => Explicit::null()];

        self::assertSame(['type' => 'static', 'value' => null], json_decode(json_encode($u), true)['attributes']['utm_term']);

    }

}
