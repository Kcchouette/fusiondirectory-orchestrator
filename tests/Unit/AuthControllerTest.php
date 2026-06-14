<?php

declare(strict_types=1);

namespace Orchestrator\Tests\Unit;

use Orchestrator\Auth\JWTCodec;
use Orchestrator\Http\Controller\AuthController;
use Orchestrator\Auth\UserGateway;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class AuthControllerTest extends TestCase
{
    private AuthController $controller;
    private JWTCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new JWTCodec('test-secret');

        $this->controller = new AuthController(
            $this->codec,
            $this->createMock(UserGateway::class),
        );
    }

    private function jsonResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function createRequest(string $method, string $uri, ?array $parsedBody = null): Request
    {
        $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
        $request = $factory->createServerRequest($method, $uri);

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }

    public function testLoginReturns400WhenMissingCredentials(): void
    {
        $request = $this->createRequest('POST', '/api/login', []);

        $response = $this->controller->login($request, new Response());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Missing login credentials', $this->jsonResponse($response)['message']);
    }

    public function testLoginReturns401WhenInvalidCredentials(): void
    {
        $userGateway = $this->createMock(UserGateway::class);
        $userGateway->method('authenticateDSA')->willReturn(false);

        $controller = new AuthController($this->codec, $userGateway);

        $request = $this->createRequest('POST', '/api/login', ['username' => 'user', 'password' => 'wrong']);

        $response = $controller->login($request, new Response());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('Invalid authentication', $this->jsonResponse($response)['message']);
    }

    public function testLogoutReturns400WhenMissingToken(): void
    {
        $request = $this->createRequest('POST', '/api/logout', []);

        $response = $this->controller->logout($request, new Response());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Missing token', $this->jsonResponse($response)['message']);
    }

    public function testLogoutReturns400WhenInvalidToken(): void
    {
        $request = $this->createRequest('POST', '/api/logout', ['token' => 'invalid-token']);

        $response = $this->controller->logout($request, new Response());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Invalid token', $this->jsonResponse($response)['message']);
    }

    public function testRefreshReturns400WhenMissingToken(): void
    {
        $request = $this->createRequest('POST', '/api/refresh', []);

        $response = $this->controller->refresh($request, new Response());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Missing token', $this->jsonResponse($response)['message']);
    }

    public function testRefreshReturns400WhenInvalidToken(): void
    {
        $request = $this->createRequest('POST', '/api/refresh', ['token' => 'invalid-token']);

        $response = $this->controller->refresh($request, new Response());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Invalid token', $this->jsonResponse($response)['message']);
    }
}
