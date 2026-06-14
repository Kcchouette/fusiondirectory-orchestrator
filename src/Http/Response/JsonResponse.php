<?php

declare(strict_types=1);

namespace Orchestrator\Http\Response;

use Psr\Http\Message\ResponseInterface;

class JsonResponse
{
    public static function success(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    public static function error(ResponseInterface $response, string $message, int $status = 400): ResponseInterface
    {
        $response->getBody()->write(json_encode(['message' => $message]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
