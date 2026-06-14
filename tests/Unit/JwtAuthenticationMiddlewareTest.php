<?php

declare(strict_types=1);

namespace Orchestrator\Tests\Unit;

use Orchestrator\Http\Middleware\JwtAuthenticationMiddleware;
use Orchestrator\Auth\JWTCodec;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\UriFactory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;

class JwtAuthenticationMiddlewareTest extends TestCase
{
    private JWTCodec $codec;
    private JwtAuthenticationMiddleware $middleware;
    private ResponseFactoryInterface $responseFactory;

    protected function setUp(): void
    {
        $this->codec = new JWTCodec('test-secret');
        $this->responseFactory = new ResponseFactory();
        $this->middleware = new JwtAuthenticationMiddleware($this->codec, $this->responseFactory);
    }

    private function createRequest(string $method, string $uri, ?string $authHeader = null): Request
    {
        $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
        $request = $factory->createServerRequest($method, $uri);

        if ($authHeader !== null) {
            $request = $request->withHeader('Authorization', $authHeader);
        }

        return $request;
    }

    public function testMissingAuthorizationHeaderReturns400(): void
    {
        $request = $this->createRequest('GET', '/api/tasks');
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(400, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Incomplete authorization header', $body['message']);
    }

    public function testInvalidTokenFormatReturns400(): void
    {
        $request = $this->createRequest('GET', '/api/tasks', 'Bearer not-a-jwt');
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Invalid token format', $body['message']);
    }

    public function testInvalidSignatureReturns401(): void
    {
        $wrongCodec = new JWTCodec('wrong-key');
        $token = $wrongCodec->encode(['sub' => 'user1', 'exp' => time() + 3600]);
        $request = $this->createRequest('GET', '/api/tasks', "Bearer $token");
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testExpiredTokenReturns401(): void
    {
        $token = $this->codec->encode(['sub' => 'user1', 'exp' => time() - 1]);
        $request = $this->createRequest('GET', '/api/tasks', "Bearer $token");
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testValidTokenAddsAttributesAndProceeds(): void
    {
        $token = $this->codec->encode(['sub' => 'testuser', 'exp' => time() + 3600]);
        $request = $this->createRequest('GET', '/api/tasks', "Bearer $token");

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($req) {
                return $req->getAttribute('dsa_cn') === 'testuser'
                    && $req->getAttribute('jwt_payload')['sub'] === 'testuser';
            }))
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }
}
