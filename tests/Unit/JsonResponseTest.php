<?php

declare(strict_types=1);

namespace Orchestrator\Tests\Unit;

use Orchestrator\Http\Response\JsonResponse;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class JsonResponseTest extends TestCase
{
    public function testSuccessReturnsJsonWith200(): void
    {
        $response = new Response();
        $data = ['key' => 'value'];

        $result = JsonResponse::success($response, $data);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(['key' => 'value'], $body);
    }

    public function testSuccessWithCustomStatus(): void
    {
        $response = new Response();
        $data = ['created' => true];

        $result = JsonResponse::success($response, $data, 201);

        $this->assertEquals(201, $result->getStatusCode());
    }

    public function testErrorReturnsJsonWithStatus(): void
    {
        $response = new Response();

        $result = JsonResponse::error($response, 'Not found', 404);

        $this->assertEquals(404, $result->getStatusCode());
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(['message' => 'Not found'], $body);
    }

    public function testErrorDefaultStatus(): void
    {
        $response = new Response();

        $result = JsonResponse::error($response, 'Bad request');

        $this->assertEquals(400, $result->getStatusCode());
    }
}
