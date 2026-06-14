<?php

declare(strict_types=1);

namespace Orchestrator\Tests\Unit;

use Orchestrator\Auth\JWTCodec;
use Orchestrator\Exception\InvalidSignatureException;
use Orchestrator\Exception\TokenExpiredException;
use PHPUnit\Framework\TestCase;

class JWTCodecTest extends TestCase
{
    private JWTCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new JWTCodec('test-secret-key');
    }

    public function testEncodeReturnsString(): void
    {
        $payload = ['sub' => 'user1', 'exp' => time() + 3600];
        $token = $this->codec->encode($payload);

        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);
    }

    public function testEncodeDecodesCorrectly(): void
    {
        $payload = ['sub' => 'testuser', 'exp' => time() + 3600];
        $token = $this->codec->encode($payload);
        $decoded = $this->codec->decode($token);

        $this->assertEquals('testuser', $decoded['sub']);
        $this->assertArrayHasKey('exp', $decoded);
    }

    public function testDecodeThrowsOnInvalidSignature(): void
    {
        $payload = ['sub' => 'user1', 'exp' => time() + 3600];
        $token = $this->codec->encode($payload);

        $tamperedCodec = new JWTCodec('different-key');

        $this->expectException(InvalidSignatureException::class);
        $tamperedCodec->decode($token);
    }

    public function testDecodeThrowsOnExpiredToken(): void
    {
        $payload = ['sub' => 'user1', 'exp' => time() - 1];
        $token = $this->codec->encode($payload);

        $this->expectException(TokenExpiredException::class);
        $this->codec->decode($token);
    }

    public function testDecodeThrowsOnInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->codec->decode('invalid-token');
    }

    public function testDifferentKeysProduceDifferentTokens(): void
    {
        $codec1 = new JWTCodec('key-one');
        $codec2 = new JWTCodec('key-two');

        $payload = ['sub' => 'user1', 'exp' => time() + 3600];

        $token1 = $codec1->encode($payload);
        $token2 = $codec2->encode($payload);

        $this->assertNotEquals($token1, $token2);
    }
}
