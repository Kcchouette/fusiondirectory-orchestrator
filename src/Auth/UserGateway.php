<?php

declare(strict_types=1);

namespace Orchestrator\Auth;

use Orchestrator\Ldap\Ldap;
use Orchestrator\Ldap\Backend;

class UserGateway
{
    private Backend $fdConfiguration;

    public function __construct(
        private readonly Ldap $ldap,
        ?Backend $fdConfiguration = null,
    ) {
        $this->fdConfiguration = $fdConfiguration ?? new Backend();
    }

    public function authenticateDSA(string $dsaLogin, string $password): bool
    {
        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $dsaBranch = $fdConfigAttributes[0]['fdDSARDN'][0];

        $dn = "cn=$dsaLogin," . $dsaBranch . "," . $_ENV['LDAP_BASE'];
        $userDs = ldap_connect($_ENV['LDAP_URI']);

        ldap_set_option($userDs, LDAP_OPT_PROTOCOL_VERSION, 3);
        $bind = @ldap_bind($userDs, $dn, $password);
        ldap_unbind($userDs);

        return $bind;
    }

    public function getDSAInfo(string $dsaLogin): array
    {
        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $tokenBranch = $fdConfigAttributes[0]['fdOrchestratorTokenRDN'][0] . ',' . $_ENV['LDAP_BASE'];

        $filter = "(&(objectClass=fdJWT)(cn=$dsaLogin))";
        $attrs = ['cn', 'dn'];

        $ds = $this->ldap->getConnection();
        $sr = @ldap_search($ds, $tokenBranch, $filter, $attrs);

        if ($sr === false) {
            ldap_unbind($ds);
            return [
                'cn' => $dsaLogin,
                'dn' => "cn=$dsaLogin," . $tokenBranch,
            ];
        }

        $info = ldap_get_entries($ds, $sr);
        ldap_unbind($ds);

        if ($info['count'] > 0) {
            return [
                'cn' => $info[0]['cn'][0],
                'dn' => $info[0]['dn'],
            ];
        }

        return [
            'cn' => $dsaLogin,
            'dn' => "cn=$dsaLogin," . $tokenBranch,
        ];
    }
}
