<?php

declare(strict_types=1);

namespace Orchestrator\Ldap;

class Backend
{
    public function getFDConfigAttributes(string $scope = 'subtree'): array
    {
        try {
            $fdLink = new \FusionDirectory\Ldap\Link($_ENV['LDAP_URI']);
            $fdLink->bind($_ENV['LDAP_BIND_DN'], $_ENV['LDAP_PASSWORD']);
        } catch (\Throwable $e) {
            return ['error' => 'FD Link not initialized'];
        }

        try {
            return \FusionDirectory\FusionDirectory\Configuration::getFusionDirectoryConfigAttributes(
                $fdLink,
                $_ENV['LDAP_BASE'],
                $scope
            );
        } catch (\Throwable $e) {
            return ['error' => (string) $e];
        }
    }
}
