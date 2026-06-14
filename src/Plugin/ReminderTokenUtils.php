<?php

declare(strict_types=1);

namespace Orchestrator\Plugin;

use Orchestrator\Ldap\Backend;
use Orchestrator\Task\TaskGateway;

class ReminderTokenUtils
{
    private Backend $fdConfiguration;

    public function __construct()
    {
        $this->fdConfiguration = new Backend();
    }

    public function generateToken(string $userDN, int $timeStamp, TaskGateway $gateway): string
    {
        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $salt = $fdConfigAttributes[0]['fdReminderSalt'][0];

        $payload = json_encode($userDN . $salt);
        $time = time();

        $tokenHmac = hash_hmac('sha256', $time . $payload, $_ENV['SECRET_KEY'], true);
        $token = $this->base64urlEncode($tokenHmac);

        $this->saveTokenInLdap($userDN, $token, $timeStamp, $gateway);

        return $token;
    }

    private function saveTokenInLdap(string $userDN, string $token, int $days, TaskGateway $gateway): bool
    {
        $currentTimestamp = time();
        $futureTimestamp = $currentTimestamp + ($days * 24 * 60 * 60);

        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $tokenBranch = $fdConfigAttributes[0]['fdReminderTokenRDN'][0];

        preg_match('/uid=([^,]+),ou=/', $userDN, $matches);
        $uid = $matches[1];
        $dn = 'cn=' . $uid . ',' . $tokenBranch . ',' . $_ENV['LDAP_BASE'];

        $ldapEntry = [
            'objectClass' => ['top', 'fdTokenEntry'],
            'fdTokenUserDN' => $userDN,
            'fdTokenType' => 'reminder',
            'fdToken' => $token,
            'fdTokenTimestamp' => $futureTimestamp,
            'cn' => $uid,
        ];

        if ($this->tokenBranchExist($dn, $gateway)) {
            $this->removeUserToken($dn, $gateway);
        }

        try {
            $result = ldap_add($gateway->ds, $dn, $ldapEntry);
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    public function getTokenExpiration(int $subTaskCall, int $firstCall, int $secondCall): int
    {
        $result = $secondCall;

        if (!empty($firstCall)) {
            if ($subTaskCall === $firstCall) {
                $result = $firstCall - $secondCall;
            }
        }

        return $result;
    }

    private function removeUserToken(string $userTokenDN, TaskGateway $gateway): void
    {
        try {
            ldap_delete($gateway->ds, $userTokenDN);
        } catch (\Exception $e) {
            // silently fail
        }
    }

    public function generateTokenUrl(string $token, array $mailTemplateForm, string $taskDN): array
    {
        preg_match('/cn=([^,]+),ou=/', $taskDN, $matches);
        $taskName = $matches[1];

        $cleanedUrl = preg_replace('#/rest\.php/v1$#', '', $_ENV['FUSIONDIRECTORY_WEBSERVICE_URL']);
        $url = $cleanedUrl . '/accountProlongation.php?token=' . $token . '&task=' . $taskName;

        $mailTemplateForm['body'] .= PHP_EOL . PHP_EOL . $url;

        return $mailTemplateForm;
    }

    private function tokenBranchExist(string $dn, TaskGateway $gateway): bool
    {
        try {
            $search = ldap_search($gateway->ds, $dn, '(objectClass=*)');
            if ($search) {
                $entries = ldap_get_entries($gateway->ds, $search);
                return $entries['count'] > 0;
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    private function base64urlEncode(string $text): string
    {
        return str_replace(['+', '/', '='], ['A', 'B', ''], base64_encode($text));
    }
}
