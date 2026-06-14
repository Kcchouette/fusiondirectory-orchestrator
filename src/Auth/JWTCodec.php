<?php

declare(strict_types=1);

namespace Orchestrator\Auth;

use Orchestrator\Exception\InvalidSignatureException;
use Orchestrator\Exception\TokenExpiredException;

class JWTCodec
{
    public function __construct(
        private readonly string $key,
    ) {
    }

    public function encode(array $payload): string
    {
        $header = $this->base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64urlEncode(json_encode($payload));
        $signature = $this->base64urlEncode(
            hash_hmac('sha256', $header . '.' . $payload, $this->key, true)
        );

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * @throws InvalidSignatureException
     * @throws TokenExpiredException
     */
    public function decode(string $token): array
    {
        if (preg_match('/^(?<header>.+)\.(?<payload>.+)\.(?<signature>.+)$/', $token, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid token format');
        }

        $signature = hash_hmac('sha256', $matches['header'] . '.' . $matches['payload'], $this->key, true);
        $signatureFromToken = $this->base64urlDecode($matches['signature']);

        if (!hash_equals($signature, $signatureFromToken)) {
            throw new InvalidSignatureException();
        }

        $payload = json_decode($this->base64urlDecode($matches['payload']), true);

        if ($payload['exp'] < time()) {
            throw new TokenExpiredException();
        }

        return $payload;
    }

    private function base64urlEncode(string $text): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($text));
    }

    private function base64urlDecode(string $text): string
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $text));
    }
}
