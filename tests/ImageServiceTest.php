<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\UpdateImage;
use nickdnk\Klaviyo\Resources\Request\UploadImageFromUrl;
use nickdnk\Klaviyo\Resources\Response\Image;
use PHPUnit\Framework\TestCase;

/**
 * Wire format for the image library: the JSON:API list / get / update / url-import
 * endpoints on `images`, plus the `image-upload` endpoint that takes the bytes as
 * `multipart/form-data` instead.
 */
class ImageServiceTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), fn() => new APIClient('tkn'));

    }

    private static function json(mixed $data, int $status = 200): Response
    {

        return new Response($status, [], json_encode(['data' => $data]));

    }

    private static function path(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getUri()->getPath();

    }

    private static function query(MockHandler $mock): array
    {

        parse_str($mock->getLastRequest()->getUri()->getQuery(), $out);

        return $out;

    }

    private static function body(MockHandler $mock): array
    {

        return json_decode((string)$mock->getLastRequest()->getBody(), true);

    }

    public function testListAndGet(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_images.200'),
            Fixtures::response('get_image.200'),
        ]);
        $images = self::client($mock)->images;

        $list = $images->list(
            (new Query())
                ->fields('image', 'name', 'image_url')
                ->filter(Filter::equals('hidden', false))
                ->sort('updated_at', true)
                ->pageSize(20)
                ->cursor('cur1')
        );
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/images', self::path($mock));
        self::assertSame([
            'fields' => ['image' => 'name,image_url'],
            'filter' => 'equals(hidden,false)',
            'sort'   => '-updated_at',
            'page'   => ['size' => '20', 'cursor' => 'cur1'],
        ], self::query($mock));
        self::assertCount(1, $list['data']);
        self::assertInstanceOf(Image::class, $list['data'][0]);
        self::assertSame('366547886', $list['data'][0]->id);
        self::assertSame('sdk-smoke-0904a5e6-img-remote', $list['data'][0]->name);
        self::assertTrue($list['data'][0]->hidden);
        // Recorded with fields[image]=name,hidden, so the url and size are absent.
        self::assertNull($list['data'][0]->image_url);
        self::assertNull($list['data'][0]->size);

        $image = $images->get('i1', (new Query())->fields('image', 'image_url'));
        self::assertSame('/api/images/i1', self::path($mock));
        self::assertSame(['fields' => ['image' => 'image_url']], self::query($mock));
        self::assertInstanceOf(Image::class, $image);
        self::assertSame('366547888', $image->id);
        self::assertSame('sdk-smoke-0904a5e6-img-file', $image->name);

    }

    public function testGetReturnsNullOnUnknownId(): void
    {

        $mock = new MockHandler([Fixtures::response('get_image.404')]);

        self::assertNull(self::client($mock)->images->get('missing'));
        self::assertSame('/api/images/missing', self::path($mock));

    }

    public function testUploadFromUrlSendsJsonApiBody(): void
    {

        $mock = new MockHandler([
            Fixtures::response('upload_image_from_url.201'),
            // The second call only exercises the outgoing body.
            self::json(['type' => 'image', 'id' => 'i4', 'attributes' => ['name' => null]], 201),
        ]);
        $images = self::client($mock)->images;

        $image = $images->uploadFromUrl(new UploadImageFromUrl('https://example.test/hero.png', 'Hero', false));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/images', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'image',
                'attributes' => [
                    'import_from_url' => 'https://example.test/hero.png',
                    'name'            => 'Hero',
                    'hidden'          => false,
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(Image::class, $image);
        self::assertSame('366547581', $image->id);
        self::assertSame('sdk-smoke-09048514-img', $image->name);
        self::assertSame('png', $image->format);
        self::assertSame(73, $image->size);
        self::assertFalse($image->hidden);
        self::assertStringEndsWith('.png', $image->image_url);

        $images->uploadFromUrl(new UploadImageFromUrl('data:image/png;base64,AAAA'));
        self::assertSame([
            'data' => [
                'type'       => 'image',
                'attributes' => ['import_from_url' => 'data:image/png;base64,AAAA'],
            ],
        ], self::body($mock), 'Omitted name / hidden must not be sent as nulls.');

    }

    public function testUpdateImageSendsIdAndAttributes(): void
    {

        $mock = new MockHandler([Fixtures::response('update_image.200')]);

        $update = new UpdateImage('i1');
        $update->name = 'Renamed';
        $update->hidden = true;

        $image = self::client($mock)->images->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/images/i1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'image',
                'attributes' => ['name' => 'Renamed', 'hidden' => true],
                'id'         => 'i1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Image::class, $image);
        self::assertSame('366547884', $image->id);
        self::assertSame('sdk-smoke-0904a5e6-img-url-renamed', $image->name);
        self::assertTrue($image->hidden);

    }

    public function testUploadFromFileSendsMultipartFormData(): void
    {

        $mock = new MockHandler([
            Fixtures::response('upload_image_from_file.201'),
            // The second call only exercises the outgoing multipart body.
            self::json(['type' => 'image', 'id' => 'i6', 'attributes' => ['name' => 'logo.png']], 201),
        ]);
        $images = self::client($mock)->images;

        $image = $images->uploadFromFile('PNGBYTES', 'logo.png', 'Logo', true);
        $request = $mock->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/image-upload', self::path($mock));
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));

        $raw = (string)$request->getBody();
        self::assertStringContainsString('name="file"; filename="logo.png"', $raw);
        self::assertStringContainsString('PNGBYTES', $raw);
        self::assertStringContainsString('name="name"', $raw);
        self::assertStringContainsString('Logo', $raw);
        self::assertStringContainsString('name="hidden"', $raw);
        self::assertStringContainsString("\r\n\r\ntrue\r\n", $raw, 'Booleans go over the wire as true / false strings.');

        self::assertInstanceOf(Image::class, $image);
        self::assertSame('366547888', $image->id);
        self::assertSame('sdk-smoke-0904a5e6-img-file', $image->name);
        self::assertSame('png', $image->format);
        self::assertSame(70, $image->size);
        // Klaviyo stores the upload on its CDN and answers with that url, not the file name.
        self::assertStringStartsWith('https://d3k81ch9hvuctc.cloudfront.net/', $image->image_url);

        $images->uploadFromFile('JPGBYTES', 'logo.jpg');
        $raw = (string)$mock->getLastRequest()->getBody();
        self::assertStringContainsString('name="file"; filename="logo.jpg"', $raw);
        self::assertStringNotContainsString('name="name"', $raw);
        self::assertStringNotContainsString('name="hidden"', $raw);

    }

}
