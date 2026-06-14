<?php

declare(strict_types=1);

namespace Orchestrator\Http\Controller;

use Orchestrator\Auth\JWTCodec;
use Orchestrator\Auth\RefreshTokenGateway;
use Orchestrator\Auth\UserGateway;
use Orchestrator\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthController
{
    public function __construct(
        private readonly JWTCodec $codec,
        private readonly UserGateway $userGateway,
    ) {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();

        if (!array_key_exists('username', $data) || !array_key_exists('password', $data)) {
            return JsonResponse::error($response, 'Missing login credentials', 400);
        }

        if (!$this->userGateway->authenticateDSA($data['username'], $data['password'])) {
            return JsonResponse::error($response, 'Invalid authentication', 401);
        }

        $user = $this->userGateway->getDSAInfo($data['username']);

        $payload = [
            'sub' => $user['cn'],
            'name' => $user['cn'],
            'exp' => time() + (int) $_ENV['TOKEN_EXPIRY'],
        ];

        $accessToken = $this->codec->encode($payload);

        $refreshTokenExpiry = time() + (int) $_ENV['REFRESH_EXPIRY'];
        $refreshToken = $this->codec->encode([
            'sub' => $user['cn'],
            'exp' => $refreshTokenExpiry,
        ]);

        $refreshTokenGateway = new RefreshTokenGateway(
            new \Orchestrator\Ldap\Ldap($_ENV['LDAP_URI'], $_ENV['LDAP_BIND_DN'], $_ENV['LDAP_PASSWORD']),
            $_ENV['SECRET_KEY'],
            $user,
        );
        $refreshTokenGateway->create($refreshToken, $refreshTokenExpiry);

        return JsonResponse::success($response, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();

        if (!array_key_exists('token', $data)) {
            return JsonResponse::error($response, 'Missing token', 400);
        }

        try {
            $this->codec->decode($data['token']);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Invalid token', 400);
        }

        $refreshTokenGateway = new RefreshTokenGateway(
            new \Orchestrator\Ldap\Ldap($_ENV['LDAP_URI'], $_ENV['LDAP_BIND_DN'], $_ENV['LDAP_PASSWORD']),
            $_ENV['SECRET_KEY'],
        );

        if (!$refreshTokenGateway->delete($data['token'])) {
            return JsonResponse::error($response, 'Error logging out, either wrong refresh token passed or already logged out!');
        }

        return JsonResponse::success($response, ['message' => 'Logged out successfully']);
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();

        if (!array_key_exists('token', $data)) {
            return JsonResponse::error($response, 'Missing token', 400);
        }

        try {
            $payload = $this->codec->decode($data['token']);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Invalid token', 400);
        }

        $dsaCN = $payload['sub'];

        $ldap = new \Orchestrator\Ldap\Ldap($_ENV['LDAP_URI'], $_ENV['LDAP_BIND_DN'], $_ENV['LDAP_PASSWORD']);
        $backend = new \Orchestrator\Ldap\Backend();
        $fdConfigAttributes = $backend->getFDConfigAttributes();
        $orchestratorAccountBranch = $fdConfigAttributes[0]['fdOrchestratorTokenRDN'][0];

        $user = [
            'cn' => $dsaCN,
            'dn' => "cn=$dsaCN,$orchestratorAccountBranch," . $_ENV['LDAP_BASE'],
        ];

        $refreshTokenGateway = new RefreshTokenGateway($ldap, $_ENV['SECRET_KEY'], $user);
        $refreshToken = $refreshTokenGateway->getByToken($data['token']);

        if (!$refreshToken) {
            return JsonResponse::error($response, 'Invalid token (not on whitelist)', 400);
        }

        $accessToken = $this->codec->encode([
            'sub' => $dsaCN,
            'name' => $dsaCN,
            'exp' => time() + (int) $_ENV['TOKEN_EXPIRY'],
        ]);

        $refreshTokenExpiry = time() + (int) $_ENV['REFRESH_EXPIRY'];
        $newRefreshToken = $this->codec->encode([
            'sub' => $dsaCN,
            'exp' => $refreshTokenExpiry,
        ]);

        $refreshTokenGateway->delete($data['token']);
        $refreshTokenGateway->create($newRefreshToken, $refreshTokenExpiry);

        return JsonResponse::success($response, [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
        ]);
    }
}
