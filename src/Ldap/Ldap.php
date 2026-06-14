<?php

declare(strict_types=1);

namespace Orchestrator\Ldap;

class Ldap
{
    public function __construct(
        private readonly string $ldapHost,
        private readonly string $ldapAdmin,
        private readonly string $ldapPwd,
    ) {
    }

    public function getConnection(): \LDAP\Connection|false
    {
        $ds = ldap_connect($this->ldapHost);

        if (!$ds) {
            return false;
        }

        ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        $ldapBind = ldap_bind($ds, $this->ldapAdmin, $this->ldapPwd);

        if (!$ldapBind) {
            ldap_unbind($ds);
            return false;
        }

        return $ds;
    }

    public function searchInLdap(
        \LDAP\Connection $ds,
        string $filter = '',
        array $attrs = [],
        ?string $dn = null,
    ): array {
        $result = [];

        if (empty($dn)) {
            $dn = $_ENV['LDAP_BASE'];
        }

        try {
            $sr = ldap_search($ds, $dn, $filter, $attrs);
            $info = ldap_get_entries($ds, $sr);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        if (!empty($info) && $info['count'] >= 1) {
            return $info;
        }

        return $result;
    }
}
