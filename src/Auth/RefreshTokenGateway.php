<?php

declare(strict_types=1);

namespace Orchestrator\Auth;

use Orchestrator\Ldap\Ldap;
use Orchestrator\Ldap\Backend;

class RefreshTokenGateway
{
    private Backend $fdConfiguration;

    public function __construct(
        private readonly Ldap $ldap,
        private readonly string $key,
        private readonly ?array $user = null,
        ?Backend $fdConfiguration = null,
    ) {
        $this->fdConfiguration = $fdConfiguration ?? new Backend();
    }

    public function create(string $token, int $expiry): bool
    {
        $ds = $this->ldap->getConnection();
        $hash = hash_hmac('sha256', $token, $this->key);

        $ldapEntry = [
            'cn' => $this->user['cn'],
            'fdRefreshToken' => $hash,
            'fdRefreshTokenExpiry' => $expiry,
            'objectclass' => 'fdJWT',
        ];

        try {
            $result = ldap_add($ds, $this->user['dn'], $ldapEntry);
        } catch (\Exception $e) {
            try {
                unset($ldapEntry['objectclass'], $ldapEntry['cn']);
                $result = ldap_modify($ds, $this->user['dn'], $ldapEntry);
            } catch (\Exception $e) {
                $result = false;
            }
        }

        ldap_unbind($ds);

        return $result;
    }

    public function delete(string $token): bool
    {
        $ds = $this->ldap->getConnection();
        $hash = hash_hmac('sha256', $token, $this->key);

        $filter = "(|(fdRefreshToken=$hash*))";
        $attrs = ['fdRefreshToken'];

        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $tokenBranch = $fdConfigAttributes[0]['fdOrchestratorTokenRDN'][0];

        $sr = ldap_search($ds, $tokenBranch . "," . $_ENV['LDAP_BASE'], $filter, $attrs);
        $info = ldap_get_entries($ds, $sr);

        $result = false;
        if (!empty($info[0])) {
            try {
                $result = ldap_mod_del($ds, $info[0]['dn'], ['fdRefreshToken' => []]);
            } catch (\Exception $e) {
                $result = false;
            }
        }

        ldap_unbind($ds);

        return $result;
    }

    public function getByToken(string $token): array
    {
        $ds = $this->ldap->getConnection();
        $hash = hash_hmac('sha256', $token, $this->key);

        $filter = "(|(fdRefreshToken=$hash*))";
        $attrs = ['fdRefreshToken'];

        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $tokenBranch = $fdConfigAttributes[0]['fdOrchestratorTokenRDN'][0];

        $sr = ldap_search($ds, $tokenBranch . ',' . $_ENV['LDAP_BASE'], $filter, $attrs);
        $info = ldap_get_entries($ds, $sr);

        ldap_unbind($ds);

        if (is_array($info) && $info['count'] >= 1) {
            return $info;
        }

        return [];
    }
}
