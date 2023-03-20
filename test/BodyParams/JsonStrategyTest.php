<?php

declare(strict_types=1);

namespace MezzioTest\Helper\BodyParams;

use Mezzio\Helper\BodyParams\JsonStrategy;
use Mezzio\Helper\Exception\MalformedRequestBodyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

use function json_last_error;

use const JSON_ERROR_NONE;

#[CoversClass(JsonStrategy::class)]
final class JsonStrategyTest extends TestCase
{
    private JsonStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new JsonStrategy();
    }

    /** @return array<array-key, string[]> */
    public static function jsonContentTypes(): array
    {
        return [
            ['application/json'],
            ['application/hal+json'],
            ['application/vnd.resource.v2+json'],
            ['application/json;charset=utf-8'],
            ['application/hal+json;charset=utf-8'],
            ['application/vnd.resource.v2+json;charset=utf-8'],
            ['application/vnd.resource.v2+json;charset=utf-8;other=value'],
        ];
    }

    #[DataProvider('jsonContentTypes')]
    public function testMatchesJsonTypes(string $contentType): void
    {
        self::assertTrue($this->strategy->match($contentType));
    }

    /** @return array<array-key, string[]> */
    public static function invalidContentTypes(): array
    {
        return [
            ['application/json+xml'],
            ['application/notjson'],
            ['application/+json'],
            ['application/ +json'],
            ['text/javascript'],
            ['form/multipart'],
            ['application/x-www-form-urlencoded'],
        ];
    }

    #[DataProvider('invalidContentTypes')]
    public function testDoesNotMatchNonJsonTypes(string $contentType): void
    {
        self::assertFalse($this->strategy->match($contentType));
    }

    /** @return ServerRequestInterface&MockObject */
    private function requestWillReturnBodyWithString(string $body): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);

        $stream
            ->expects(self::once())
            ->method('__toString')
            ->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);

        $request
            ->expects(self::once())
            ->method('getBody')
            ->willReturn($stream);

        return $request;
    }

    public function testParseReturnsNewRequest(): void
    {
        $body    = '{"foo":"bar"}';
        $request = $this->requestWillReturnBodyWithString($body);

        $request
            ->expects(self::once())
            ->method('withAttribute')
            ->with('rawBody', $body)
            ->willReturnSelf();

        $request
            ->expects(self::once())
            ->method('withParsedBody')
            ->with(['foo' => 'bar'])
            ->willReturnSelf();

        self::assertSame($request, $this->strategy->parse($request));
    }

    public function testThrowsExceptionOnMalformedJsonInRequestBody(): void
    {
        $body    = '{foobar}';
        $request = $this->requestWillReturnBodyWithString($body);

        $this->expectException(MalformedRequestBodyException::class);
        $this->expectExceptionMessage('Error when parsing JSON request body: ');
        $this->expectExceptionCode(400);

        $this->strategy->parse($request);
    }

    public function testEmptyRequestBodyYieldsNullParsedBodyWithNoExceptionThrown(): void
    {
        $body    = '';
        $request = $this->requestWillReturnBodyWithString($body);

        $request
            ->expects(self::once())
            ->method('withAttribute')
            ->with('rawBody', $body)
            ->willReturnSelf();

        $request
            ->expects(self::once())
            ->method('withParsedBody')
            ->with(null)
            ->willReturnSelf();

        self::assertSame($request, $this->strategy->parse($request));
    }

    #[RunInSeparateProcess]
    public function testEmptyRequestBodyIsNotJsonDecoded(): void
    {
        $body    = '';
        $request = $this->requestWillReturnBodyWithString($body);

        $request
            ->expects(self::once())
            ->method('withAttribute')
            ->with('rawBody', $body)
            ->willReturnSelf();

        $request
            ->expects(self::once())
            ->method('withParsedBody')
            ->with(null)
            ->willReturnSelf();

        $this->strategy->parse($request);

        self::assertSame(json_last_error(), JSON_ERROR_NONE);
    }

    /** @psalm-return iterable<string, array{0: string}> */
    public static function provideNonArrayJsonRequestBody(): iterable
    {
        yield 'null'    => ['null'];
        yield 'true'    => ['true'];
        yield 'false'   => ['false'];
        yield 'integer' => ['1'];
        yield 'float'   => ['1.0'];
        yield 'string'  => ['"string"'];
    }

    #[DataProvider('provideNonArrayJsonRequestBody')]
    public function testParsedBodyEvaluatingToNonArrayValueResultsInNull(string $json): void
    {
        $request = $this->requestWillReturnBodyWithString($json);

        $request
            ->expects(self::once())
            ->method('withAttribute')
            ->with('rawBody', $json)
            ->willReturnSelf();

        $request
            ->expects(self::once())
            ->method('withParsedBody')
            ->with(null)
            ->willReturnSelf();

        $this->strategy->parse($request);
    }
}
