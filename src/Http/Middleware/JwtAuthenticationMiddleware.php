<?php

declare(strict_types=1);

namespace Orchestrator\Http\Middleware;

use Orchestrator\Auth\JWTCodec;
use Orchestrator\Exception\InvalidSignatureException;
use Orchestrator\Exception\TokenExpiredException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JwtAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JWTCodec $codec,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->jsonError(400, 'Incomplete authorization header');
        }

        try {
            $payload = $this->codec->decode($matches[1]);
        } catch (InvalidSignatureException) {
            return $this->jsonError(401, 'Invalid signature');
        } catch (TokenExpiredException) {
            return $this->jsonError(401, 'Token has expired');
        } catch (\InvalidArgumentException) {
            return $this->jsonError(400, 'Invalid token format');
        }

        $request = $request
            ->withAttribute('jwt_payload', $payload)
            ->withAttribute('dsa_cn', $payload['sub']);

        return $handler->handle($request);
    }

    private function jsonError(int $status, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(json_encode(['message' => $message]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
