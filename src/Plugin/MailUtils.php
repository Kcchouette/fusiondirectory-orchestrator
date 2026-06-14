<?php

declare(strict_types=1);

namespace Orchestrator\Plugin;

use Orchestrator\Task\TaskGateway;

class MailUtils
{
    public function sendMail(
        $setFrom,
        $setBCC,
        $recipients,
        $body,
        $signature,
        $subject,
        $receipt,
        $attachments,
    ): array {
        $mailController = new \FusionDirectory\Mail\MailLib(
            $setFrom,
            $setBCC,
            $recipients,
            $body,
            $signature,
            $subject,
            $receipt,
            $attachments
        );

        return $mailController->sendMail();
    }

    public function getMailObjectConfiguration(TaskGateway $gateway): array
    {
        return $gateway->getLdapTasks(
            '(objectClass=fdTasksConf)',
            ['fdTasksConfLastExecTime', 'fdTasksConfIntervalEmails', 'fdTasksConfMaxEmails']
        );
    }

    public function returnMaximumMailToBeSend(array $fdTasksConf): int
    {
        return $fdTasksConf[0]['fdtasksconfmaxemails'][0] ?? 50;
    }

    public function replaceMacros(TaskGateway $gateway, array|string $recipients, string $body, array $mailMacros): string
    {
        $hardcodedMacros = [];
        foreach ($mailMacros as $macro) {
            $pattern = explode('|', $macro)[0];
            $ldapValue = explode('|', $macro)[1];
            $hardcodedMacros[$pattern] = $ldapValue;
        }

        if (is_string($recipients)) {
            $recipients = [$recipients];
        }

        foreach ($recipients as $recipient) {
            foreach ($hardcodedMacros as $pattern => $ldapValue) {
                $filter = "(&(objectClass=inetOrgPerson)(|(mail=$recipient)(gosaMailAlternateAddress=$recipient)(gosaMailForwardingAddress=$recipient)(supannAutreMail=$recipient)(supannMailPerso=$recipient)(supannMailPrive={*}$recipient)))";
                $ldapAttribute = $gateway->getLdapTasks($filter, [$ldapValue]);
                if (isset($ldapAttribute[0][strtolower($ldapValue)][0])) {
                    $body = preg_replace('/%' . $pattern . '%/', $ldapAttribute[0][strtolower($ldapValue)][0], $body);
                }
            }
        }

        return $body;
    }
}
