<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MockHttpClientTest extends HttpClientTestCase
{
    /**
     * @dataProvider validResponseFactoryProvider
     */
    public function testValidResponseFactory($responseFactory)
    {
        (new MockHttpClient($responseFactory))->request('GET', 'https://foo.bar');

        $this->addToAssertionCount(1);
    }

    public function validResponseFactoryProvider()
    {
        return [
            [static function (): MockResponse { return new MockResponse(); }],
            [new MockResponse()],
            [[new MockResponse()]],
            [new \ArrayIterator([new MockResponse()])],
            [null],
            [(static function (): \Generator { yield new MockResponse(); })()],
        ];
    }

    /**
     * @dataProvider invalidResponseFactoryProvider
     */
    public function testInvalidResponseFactory($responseFactory, string $expectedExceptionMessage)
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        (new MockHttpClient($responseFactory))->request('GET', 'https://foo.bar');
    }

    public function invalidResponseFactoryProvider()
    {
        return [
            [static function (): \Generator { yield new MockResponse(); }, 'The response factory passed to MockHttpClient must return/yield an instance of ResponseInterface, "Generator" given.'],
            [static function (): array { return [new MockResponse()]; }, 'The response factory passed to MockHttpClient must return/yield an instance of ResponseInterface, "array" given.'],
            [(static function (): \Generator { yield 'ccc'; })(), 'The response factory passed to MockHttpClient must return/yield an instance of ResponseInterface, "string" given.'],
        ];
    }

    public function testZeroStatusCode()
    {
        $client = new MockHttpClient(new MockResponse('', ['response_headers' => ['HTTP/1.1 000 ']]));
        $response = $client->request('GET', 'https://foo.bar');
        $this->assertSame(0, $response->getStatusCode());
    }

    public function testFixContentLength()
    {
        $client = new MockHttpClient();

        $response = $client->request('POST', 'http://localhost:8057/post', [
            'body' => 'abc=def',
            'headers' => ['Content-Length: 4'],
        ]);

        $requestOptions = $response->getRequestOptions();
        $this->assertSame('Content-Length: 7', $requestOptions['headers'][0]);
        $this->assertSame(['Content-Length: 7'], $requestOptions['normalized_headers']['content-length']);

        $response = $client->request('POST', 'http://localhost:8057/post', [
            'body' => 'abc=def',
        ]);

        $requestOptions = $response->getRequestOptions();
        $this->assertSame('Content-Length: 7', $requestOptions['headers'][1]);
        $this->assertSame(['Content-Length: 7'], $requestOptions['normalized_headers']['content-length']);

        $response = $client->request('POST', 'http://localhost:8057/post', [
            'body' => "8\r\nSymfony \r\n5\r\nis aw\r\n6\r\nesome!\r\n0\r\n\r\n",
            'headers' => ['Transfer-Encoding: chunked'],
        ]);

        $requestOptions = $response->getRequestOptions();
        $this->assertSame(['Content-Length: 19'], $requestOptions['normalized_headers']['content-length']);

        $response = $client->request('POST', 'http://localhost:8057/post', [
            'body' => '',
        ]);

        $requestOptions = $response->getRequestOptions();
        $this->assertFalse(isset($requestOptions['normalized_headers']['content-length']));
    }

    public function testThrowExceptionInBodyGenerator()
    {
        $mockHttpClient = new MockHttpClient([
            new MockResponse((static function (): \Generator {
                yield 'foo';
                throw new TransportException('foo ccc');
            })()),
            new MockResponse((static function (): \Generator {
                yield 'bar';
                throw new \RuntimeException('bar ccc');
            })()),
        ]);

        try {
            $mockHttpClient->request('GET', 'https://symfony.com', [])->getContent();
            $this->fail();
        } catch (TransportException $e) {
            $this->assertEquals(new TransportException('foo ccc'), $e->getPrevious());
            $this->assertSame('foo ccc', $e->getMessage());
        }

        $chunks = [];
        try {
            foreach ($mockHttpClient->stream($mockHttpClient->request('GET', 'https://symfony.com', [])) as $chunk) {
                $chunks[] = $chunk;
            }
            $this->fail();
        } catch (TransportException $e) {
            $this->assertEquals(new \RuntimeException('bar ccc'), $e->getPrevious());
            $this->assertSame('bar ccc', $e->getMessage());
        }

        $this->assertCount(3, $chunks);
        $this->assertEquals(new FirstChunk(0, ''), $chunks[0]);
        $this->assertEquals(new DataChunk(0, 'bar'), $chunks[1]);
        $this->assertInstanceOf(ErrorChunk::class, $chunks[2]);
        $this->assertSame(3, $chunks[2]->getOffset());
        $this->assertSame('bar ccc', $chunks[2]->getError());
    }

    public function testMergeDefaultOptions()
    {
        $mockHttpClient = new MockHttpClient(null, 'https://example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL: scheme is missing');
        $mockHttpClient->request('GET', '/foo', ['base_uri' => null]);
    }

    protected function getHttpClient(string $testCase): HttpClientInterface
    {
        $responses = [];

        $headers = [
          'Host: localhost:8057',
          'Content-Type: application/json',
        ];

        $body = '{
    "SERVER_PROTOCOL": "HTTP/1.1",
    "SERVER_NAME": "127.0.0.1",
    "REQUEST_URI": "/",
    "REQUEST_METHOD": "GET",
    "HTTP_ACCEPT": "*/*",
    "HTTP_FOO": "baR",
    "HTTP_HOST": "localhost:8057"
}';

        $client = new NativeHttpClient();

        switch ($testCase) {
            default:
                return new MockHttpClient(function (string $method, string $url, array $options) use ($client) {
                    try {
                        // force the request to be completed so that we don't test side effects of the transport
                        $response = $client->request($method, $url, ['buffer' => false] + $options);
                        $content = $response->getContent(false);

                        return new MockResponse($content, $response->getInfo());
                    } catch (\Throwable $e) {
                        $this->fail($e->getMessage());
                    }
                });

            case 'testUnsupportedOption':
                $this->markTestSkipped('MockHttpClient accepts any options by default');
                break;

            case 'testChunkedEncoding':
                $this->markTestSkipped("MockHttpClient doesn't dechunk");
                break;

            case 'testGzipBroken':
                $this->markTestSkipped("MockHttpClient doesn't unzip");
                break;

            case 'testTimeoutWithActiveConcurrentStream':
                $this->markTestSkipped('Real transport required');
                break;

            case 'testTimeoutOnInitialize':
            case 'testTimeoutOnDestruct':
                $this->markTestSkipped('Real transport required');
                break;

            case 'testDestruct':
                $this->markTestSkipped("MockHttpClient doesn't timeout on destruct");
                break;

            case 'testHandleIsRemovedOnException':
                $this->markTestSkipped("MockHttpClient doesn't cache handles");
                break;

            case 'testGetRequest':
                array_unshift($headers, 'HTTP/1.1 200 OK');
                $responses[] = new MockResponse($body, ['response_headers' => $headers]);

                $headers = [
                  'Host: localhost:8057',
                  'Content-Length: 1000',
                  'Content-Type: application/json',
                ];

                $responses[] = new MockResponse($body, ['response_headers' => $headers]);
                break;

            case 'testDnsError':
                $responses[] = $mockResponse = new MockResponse('', ['error' => 'DNS error']);
                $responses[] = $mockResponse;
                break;

            case 'testToStream':
            case 'testBadRequestBody':
            case 'testOnProgressCancel':
            case 'testOnProgressError':
            case 'testReentrantBufferCallback':
            case 'testThrowingBufferCallback':
            case 'testInfoOnCanceledResponse':
                $responses[] = new MockResponse($body, ['response_headers' => $headers]);
                break;

            case 'testTimeoutOnAccess':
                $responses[] = new MockResponse('', ['error' => 'Timeout']);
                break;

            case 'testAcceptHeader':
                $responses[] = new MockResponse($body, ['response_headers' => $headers]);
                $responses[] = new MockResponse(str_replace('*/*', 'foo/bar', $body), ['response_headers' => $headers]);
                $responses[] = new MockResponse(str_replace('"HTTP_ACCEPT": "*/*",', '', $body), ['response_headers' => $headers]);
                break;

            case 'testResolve':
                $responses[] = new MockResponse($body, ['response_headers' => $headers]);
                $responses[] = new MockResponse($body, ['response_headers' => $headers]);
                $responses[] = new MockResponse((function () { yield ''; })(), ['response_headers' => $headers]);
                break;

            case 'testTimeoutOnStream':
            case 'testUncheckedTimeoutThrows':
            case 'testTimeoutIsNotAFatalError':
                $body = ['<1>', '', '<2>'];
                $responses[] = new MockResponse($body, ['response_headers' => $headers]);
                break;

            case 'testInformationalResponseStream':
                $client = $this->createMock(HttpClientInterface::class);
                $response = new MockResponse('Here the body', ['response_headers' => [
                    'HTTP/1.1 103 ',
                    'Link: </style.css>; rel=preload; as=style',
                    'HTTP/1.1 200 ',
                    'Date: foo',
                    'Content-Length: 13',
                ]]);
                $client->method('request')->willReturn($response);
                $client->method('stream')->willReturn(new ResponseStream((function () use ($response) {
                    $chunk = $this->createMock(ChunkInterface::class);
                    $chunk->method('getInformationalStatus')
                        ->willReturn([103, ['link' => ['</style.css>; rel=preload; as=style', '</script.js>; rel=preload; as=script']]]);

                    yield $response => $chunk;

                    $chunk = $this->createMock(ChunkInterface::class);
                    $chunk->method('isFirst')->willReturn(true);

                    yield $response => $chunk;

                    $chunk = $this->createMock(ChunkInterface::class);
                    $chunk->method('getContent')->willReturn('Here the body');

                    yield $response => $chunk;

                    $chunk = $this->createMock(ChunkInterface::class);
                    $chunk->method('isLast')->willReturn(true);

                    yield $response => $chunk;
                })()));

                return $client;

            case 'testNonBlockingStream':
                $responses[] = new MockResponse((function () { yield '<1>'; yield ''; yield '<2>'; })(), ['response_headers' => $headers]);
                break;

            case 'testMaxDuration':
                $responses[] = new MockResponse('', ['error' => 'Max duration was reached.']);
                break;
        }

        return new MockHttpClient($responses);
    }
}
