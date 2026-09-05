<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every service with `iterate()` declares it by hand with a concrete `@return Generator<int, X>`
 * (a generic through the trait does not resolve in IDEs). This walks each one against two mocked
 * pages so the declared item class, the hydrated class and the lazy page follow stay in agreement.
 */
class ServiceIterateTest extends TestCase
{

    /**
     * @return iterable<string, array{string, class-string<TypedResource>}>
     */
    public static function services(): iterable
    {

        $client = new ReflectionClass(APIClient::class);
        preg_match_all('/@property-read\s+(\w+)\s+\$(\w+)/', $client->getDocComment(), $props, PREG_SET_ORDER);

        foreach ($props as [, $serviceShort, $property]) {
            $serviceClass = 'nickdnk\\Klaviyo\\Services\\' . $serviceShort;
            if (!method_exists($serviceClass, 'iterate')) {
                continue;
            }

            $doc = (new ReflectionMethod($serviceClass, 'iterate'))->getDocComment() ?: '';
            self::assertMatchesRegularExpression('/@return Generator<int, (\w+)>/', $doc, "{$serviceShort}::iterate() must declare its item type.");
            preg_match('/@return Generator<int, (\w+)>/', $doc, $m);

            // Resolve the short name through the service's own imports, exactly as the docblock does.
            $source = file_get_contents((new ReflectionClass($serviceClass))->getFileName());
            preg_match('/^use (nickdnk\\\\Klaviyo\\\\Resources\\\\\S+\\\\' . preg_quote($m[1], '/') . ');/m', $source, $use);
            self::assertNotEmpty($use, "{$serviceShort} does not import {$m[1]}.");

            yield $property => [$property, $use[1]];
        }

    }

    /**
     * @param class-string<TypedResource> $itemClass
     */
    #[DataProvider('services')]
    public function testIterateYieldsDeclaredTypeAcrossPages(string $property, string $itemClass): void
    {

        $type = $itemClass::type();
        $item = fn(string $id) => ['type' => $type, 'id' => $id, 'attributes' => []];
        $next = 'https://a.klaviyo.com/api/anything?page%5Bcursor%5D=c2';

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['data' => [$item('a'), $item('b')], 'links' => ['self' => 'https://a.klaviyo.com/api/anything', 'next' => $next]])),
            new Response(200, [], json_encode(['data' => [$item('c')], 'links' => ['self' => $next, 'next' => null]])),
        ]));
        $stack->push(Middleware::history($history));
        $client = APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack($stack));

        $items = $client->$property->iterate((new Query())->pageSize(2));

        $ids = [];
        foreach ($items as $i => $resource) {
            self::assertInstanceOf($itemClass, $resource);
            $ids[$i] = $resource->id;
            if ($i === 0) {
                self::assertCount(1, $history, 'second page is not fetched before the first is exhausted');
            }
        }

        self::assertSame(['a', 'b', 'c'], $ids);
        self::assertCount(2, $history);
        self::assertStringContainsString('page%5Bsize%5D=2', (string)$history[0]['request']->getUri(), 'first page carries the Query');
        self::assertSame($next, (string)$history[1]['request']->getUri(), 'following page uses links.next verbatim');

    }

}
