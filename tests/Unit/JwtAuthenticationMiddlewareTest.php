<?php

declare(strict_types=1);

namespace Orchestrator\Tests\Unit;

use Orchestrator\Http\Middleware\JwtAuthenticationMiddleware;
use Orchestrator\Auth\JWTCodec;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
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

    public function testMissingAuthorizationHeaderReturns400(): void
    {
        $request = new Request('GET', '/api/tasks');
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(400, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Incomplete authorization header', $body['message']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $request = (new Request('GET', '/api/tasks'))
            ->withHeader('Authorization', 'Bearer invalid-token');
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testExpiredTokenReturns401(): void
    {
        $token = $this->codec->encode(['sub' => 'user1', 'exp' => time() - 1]);
        $request = (new Request('GET', '/api/tasks'))
            ->withHeader('Authorization', "Bearer $token");
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testValidTokenAddsAttributesAndProceeds(): void
    {
        $token = $this->codec->encode(['sub' => 'testuser', 'exp' => time() + 3600]);
        $request = (new Request('GET', '/api/tasks'))
            ->withHeader('Authorization', "Bearer $token");

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->handle($this->callback(function ($req) {
                return $req->getAttribute('dsa_cn') === 'testuser'
                    && $req->getAttribute('jwt_payload')['sub'] === 'testuser';
            }))
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }
}
