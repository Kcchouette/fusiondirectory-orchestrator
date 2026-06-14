<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Plugin\MailUtils;
use Orchestrator\Task\TaskGateway;

class Mail implements EndpointInterface
{
    private MailUtils $mailUtils;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->mailUtils = new MailUtils();
    }

    public function processEndPointGet(): array
    {
        return [];
    }

    public function processEndPointPost(?array $data = null): array
    {
        return [];
    }

    public function processEndPointDelete(?array $data = null): array
    {
        return [];
    }

    public function processEndPointPatch(?array $data = null): array
    {
        return $this->processMailTasks($this->gateway->getObjectTypeTask('mail'));
    }

    private function getMailTaskMainTask(string $mainTaskDn): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdTasks)',
            ['fdTasksRepeatableSchedule', 'fdTasksRepeatable'],
            '',
            $mainTaskDn
        );
    }

    public function processMailTasks(array $tasks): array
    {
        $result = [];
        $fdTasksConf = $this->getMailObjectConfiguration();
        $maxMailsConfig = $this->returnMaximumMailToBeSend($fdTasksConf);
        $maxMailsIncrement = 0;

        if ($this->verifySpamProtection($fdTasksConf)) {
            foreach ($tasks as $task) {
                if ($this->gateway->statusAndScheduleCheck($task)) {
                    $mainTaskDn = $task['fdtasksgranularmaster'][0];
                    $mainTaskConfig = $this->getMailTaskMainTask($mainTaskDn);

                    $repeatableSchedule = null;
                    $repeatableFlag = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? null;

                    if ($repeatableFlag !== null && strcasecmp($repeatableFlag, 'TRUE') === 0) {
                        $repeatableSchedule = $mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? null;
                    }

                    $mailInfos = $this->retrieveMailTemplateInfos($task['fdtasksgranularref'][0]);
                    $this->gateway->unsetCountKeys($mailInfos);

                    $mailContent = $mailInfos[0];
                    unset($mailInfos[0]);
                    $mailAttachments = array_values($mailInfos);

                    $mailMacros = $mailContent['fdmailtemplatemacro'] ?? [];
                    $setFrom = $task['fdtasksgranularmailfrom'][0];
                    $setBCC = $task['fdtasksgranularmailbcc'][0] ?? null;
                    $recipients = $task['fdtasksgranularmail'];
                    $body = $this->mailUtils->replaceMacros($this->gateway, $recipients, $mailContent['fdmailtemplatebody'][0], $mailMacros);
                    $signature = $mailContent['fdmailtemplatesignature'][0] ?? null;
                    $subject = $mailContent['fdmailtemplatesubject'][0];
                    $receipt = $mailContent['fdmailtemplatereadreceipt'][0];

                    $attachments = [];
                    foreach ($mailAttachments as $file) {
                        $attachments[] = [
                            'cn' => $file['cn'][0],
                            'content' => $file['fdmailattachmentscontent'][0],
                        ];
                    }

                    $mailSentResult = $this->mailUtils->sendMail($setFrom, $setBCC, $recipients, $body, $signature, $subject, $receipt, $attachments);
                    $result[$task['dn']] = $this->updateResult($mailSentResult, $task, $fdTasksConf, $mainTaskDn, $repeatableSchedule);

                    $maxMailsIncrement += 1;
                    if ($maxMailsIncrement == $maxMailsConfig) {
                        break;
                    }
                }
            }
        }

        return $result;
    }

    private function updateResult(array $mailSentResult, $task, $fdTasksConf, $mainTaskDn = null, $repeatableSchedule = null): array
    {
        $result = [];
        if ($mailSentResult[0] == 'SUCCESS') {
            if ($repeatableSchedule !== null && $mainTaskDn !== null) {
                $result['statusUpdate'] = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
            } elseif ($mainTaskDn !== null) {
                $result['statusUpdate'] = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn);
            } else {
                $result['statusUpdate'] = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
            }
            $result['mailStatus'] = 'mail : ' . $task['dn'] . ' was successfully sent';
            $result['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($fdTasksConf[0]['dn']);
        } else {
            if ($repeatableSchedule !== null && $mainTaskDn !== null) {
                $result['statusUpdate'] = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $mailSentResult[0], $mainTaskDn, $repeatableSchedule);
            } elseif ($mainTaskDn !== null) {
                $result['statusUpdate'] = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $mailSentResult[0], $mainTaskDn);
            } else {
                $result['statusUpdate'] = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $mailSentResult[0]);
            }
            $result['Error'] = $mailSentResult;
        }
        return $result;
    }

    private function getMailObjectConfiguration(): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdTasksConf)',
            ['fdTasksConfLastExecTime', 'fdTasksConfIntervalEmails', 'fdTasksConfMaxEmails']
        );
    }

    public function returnMaximumMailToBeSend(array $fdTasksConf): int
    {
        return $fdTasksConf[0]['fdtasksconfmaxemails'][0] ?? 50;
    }

    public function verifySpamProtection(array $fdTasksConf): bool
    {
        $lastExec = $fdTasksConf[0]['fdtasksconflastexectime'][0] ?? null;
        $spamInterval = $fdTasksConf[0]['fdtasksconfintervalemails'][0] ?? null;

        $spamInterval = $spamInterval * 60;
        $antispam = $lastExec + $spamInterval;

        return $antispam <= time();
    }

    public function retrieveMailTemplateInfos(string $templateName): array
    {
        return $this->gateway->getLdapTasks(
            '(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))',
            [],
            $templateName
        );
    }
}
