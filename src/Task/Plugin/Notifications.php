<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Task\TaskGateway;

class Notifications implements EndpointInterface
{
    private \Orchestrator\Plugin\CoreUtils $coreUtils;
    private \Orchestrator\Plugin\MailUtils $mailUtils;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->coreUtils = new \Orchestrator\Plugin\CoreUtils();
        $this->mailUtils = new \Orchestrator\Plugin\MailUtils();
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
        return $this->processNotifications($this->gateway->getObjectTypeTask('notifications'));
    }

    public function processNotifications(array $notificationsSubTasks): array
    {
        $result = [];
        $notifications = [];

        foreach ($notificationsSubTasks as $task) {
            if ($this->gateway->statusAndScheduleCheck($task)) {
                $mainTaskDn = $task['fdtasksgranularmaster'][0];
                $notificationsMainTask = $this->getNotificationsMainTask($mainTaskDn);

                $repeatableSchedule = null;
                $repeatableFlag = $notificationsMainTask[0]['fdtasksrepeatable'][0] ?? null;
                if ($repeatableFlag !== null && strcasecmp($repeatableFlag, 'TRUE') === 0) {
                    $repeatableSchedule = $notificationsMainTask[0]['fdtasksrepeatableschedule'][0] ?? null;
                }

                $mailTemplateForm = $this->generateMainTaskMailTemplate($notificationsMainTask);
                $auditAttributes = $this->decodeAuditAttributes($task);

                $monitoredAttrs = $notificationsMainTask[0]['fdtasksnotificationsattributes'];
                $monitoredSupannResource = $this->getSupannResourceState($notificationsMainTask[0]);

                $this->gateway->unsetCountKeys($monitoredAttrs);
                $this->gateway->unsetCountKeys($monitoredSupannResource);

                $matchingAttrs = $this->coreUtils->findMatchingKeys($auditAttributes, $monitoredAttrs);

                if ($this->shouldVerifySupannResource($monitoredSupannResource, $auditAttributes)) {
                    $matchingAttrs[] = 'supannRessourceEtat';
                }

                if (!empty($matchingAttrs)) {
                    $notifications[$mainTaskDn]['subTask'][$task['cn'][0]]['attrs'] = $matchingAttrs;
                    $notifications[$mainTaskDn]['subTask'][$task['cn'][0]]['dn'] = $task['dn'];
                    $notifications[$mainTaskDn]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
                    $notifications[$mainTaskDn]['mainTaskDn'] = $mainTaskDn;

                    if ($repeatableSchedule !== null) {
                        $notifications[$mainTaskDn]['repeatableSchedule'] = $repeatableSchedule;
                    }
                    $notifications[$mainTaskDn]['mailForm'] = $mailTemplateForm;

                    $notifications[$mainTaskDn]['fdTasksNotificationsPostResource'] = $notificationsMainTask[0]['fdtasksnotificationspostresource'][0] ?? '';
                    $notifications[$mainTaskDn]['fdTasksNotificationsPostState'] = $notificationsMainTask[0]['fdtasksnotificationspoststate'][0] ?? '';
                    $notifications[$mainTaskDn]['fdTasksNotificationsPostSubState'] = $notificationsMainTask[0]['fdtasksnotificationspostsubstate'][0] ?? '';

                    $notifications = $this->completeNotificationsBody($notifications, $mainTaskDn);
                } else {
                    $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
                        $task['dn'],
                        $task['cn'][0],
                        '3',
                        $mainTaskDn,
                        $repeatableSchedule
                    );
                    $result[$task['dn']]['Message'] = 'No matching audited attributes, nothing to process!';
                }
            }
        }

        if (!empty($notifications)) {
            $result[] = $this->sendNotificationsMail($notifications);
        }

        return $result;
    }

    private function shouldVerifySupannResource(array $monitoredSupannResource, ?array $auditAttributes): bool
    {
        if (!empty($auditAttributes)) {
            return $monitoredSupannResource['resource'][0] !== 'NONE'
                && $this->verifySupannState($monitoredSupannResource, $auditAttributes);
        }
        return false;
    }

    private function getSupannResourceState(array $notificationsMainTask): array
    {
        return [
            'resource' => $notificationsMainTask['fdtasksnotificationsresource'],
            'state' => $notificationsMainTask['fdtasksnotificationsstate'],
            'subState' => $notificationsMainTask['fdtasksnotificationssubstate'] ?? null,
        ];
    }

    private function decodeAuditAttributes(array $task): array
    {
        $auditAttributesJson = $this->retrieveAuditedAttributes($task);
        $auditAttributes = [];

        foreach ($auditAttributesJson as $auditAttribute) {
            $auditAttributes[] = json_decode(implode($auditAttribute), true);
        }

        return $auditAttributes;
    }

    private function verifySupannState(array $supannResource, array $auditedAttrs): bool
    {
        $monitoredSupannState = '{' . $supannResource['resource'][0] . '}' . $supannResource['state'][0];

        if (!empty($supannResource['subState'][0])) {
            $monitoredSupannState .= ':' . $supannResource['subState'][0];
        }

        $auditedValues = $this->coreUtils->getArrayValuesRecursive($auditedAttrs);

        return in_array($monitoredSupannState, $auditedValues);
    }

    public function getNotificationsMainTask(string $mainTaskDn): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdTasksNotifications)',
            [
                'fdTasksNotificationsListOfRecipientsMails', 'fdTasksNotificationsAttributes',
                'fdTasksNotificationsMailTemplate', 'fdTasksNotificationsEmailSender',
                'fdTasksNotificationsSubState', 'fdTasksNotificationsState', 'fdTasksNotificationsResource',
                'fdTasksRepeatableSchedule', 'fdTasksRepeatable', 'fdTasksNotificationsPostResource',
                'fdTasksNotificationsPostState', 'fdTasksNotificationsPostSubState',
            ],
            '',
            $mainTaskDn
        );
    }

    private function generateMainTaskMailTemplate(array $mainTask): array
    {
        $recipients = $mainTask[0]['fdtasksnotificationslistofrecipientsmails'];
        $this->gateway->unsetCountKeys($recipients);
        $sender = $mainTask[0]['fdtasksnotificationsemailsender'][0];
        $mailTemplateName = $mainTask[0]['fdtasksnotificationsmailtemplate'][0];

        $mailInfos = $this->gateway->getLdapTasks(
            '(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))',
            [],
            $mailTemplateName
        );
        $this->gateway->unsetCountKeys($mailInfos);

        $mailContent = $mailInfos[0];
        $mailMacros = $mailContent['fdmailtemplatemacro'] ?? [];

        $mailForm = [];
        $mailForm['setFrom'] = $sender;
        $mailForm['recipients'] = $recipients;
        $mailForm['body'] = $this->mailUtils->replaceMacros($this->gateway, $recipients, $mailContent['fdmailtemplatebody'][0], $mailMacros);
        $mailForm['signature'] = $mailContent['fdmailtemplatesignature'][0] ?? null;
        $mailForm['subject'] = $mailContent['fdmailtemplatesubject'][0];
        $mailForm['receipt'] = $mailContent['fdmailtemplatereadreceipt'][0];

        return $mailForm;
    }

    protected function retrieveAuditedAttributes(array $notificationTask): array
    {
        $auditAttributes = [];
        $auditInformation = [];

        if (!empty($notificationTask['fdtasksgranularref'])) {
            $this->gateway->unsetCountKeys($notificationTask);

            foreach ($notificationTask['fdtasksgranularref'] as $auditDN) {
                $auditInformation[] = $this->gateway->getLdapTasks(
                    '(objectClass=fdAuditEvent)',
                    ['fdAuditAttributes'],
                    '',
                    $auditDN
                );
            }

            $this->gateway->unsetCountKeys($auditInformation);

            foreach ($auditInformation as $attr) {
                if (!empty($attr[0]['fdauditattributes'])) {
                    $auditAttributes[] = $attr[0]['fdauditattributes'];
                }
            }
        }

        return $auditAttributes;
    }

    private function completeNotificationsBody(array $notifications, string $mainTaskDn): array
    {
        $uidAttrsText = [];

        foreach ($notifications[$mainTaskDn]['subTask'] as $value) {
            $uidName = $value['uid'];
            $attrs = $value['attrs'];
            $uidAttrsText[] = "\n$uidName attrs=[" . implode(', ', $attrs) . "]";
        }

        $uidAttrsText = array_unique($uidAttrsText);
        $notifications[$mainTaskDn]['mailForm']['body'] .= PHP_EOL . implode(' ', $uidAttrsText);

        return $notifications;
    }

    protected function sendNotificationsMail(array $notifications): array
    {
        $result = [];
        $fdTasksConf = $this->mailUtils->getMailObjectConfiguration($this->gateway);
        $maxMailsConfig = $this->mailUtils->returnMaximumMailToBeSend($fdTasksConf);
        $maxMailsIncrement = 0;

        foreach ($notifications as $data) {
            $numberOfRecipients = count($data['mailForm']['recipients']);

            $mailSentResult = $this->mailUtils->sendMail(
                $data['mailForm']['setFrom'],
                null,
                $data['mailForm']['recipients'],
                $data['mailForm']['body'],
                $data['mailForm']['signature'],
                $data['mailForm']['subject'],
                $data['mailForm']['receipt'],
                null
            );
            $result[] = $this->processMailResponseAndUpdateTasks($mailSentResult, $data, $fdTasksConf);

            $maxMailsIncrement += $numberOfRecipients;
            if ($maxMailsIncrement == $maxMailsConfig) {
                break;
            }
        }

        return $result;
    }

    protected function processMailResponseAndUpdateTasks(array $serverResults, array $subTask, array $mailTaskBackend): array
    {
        $result = [];
        $mainTaskDn = $subTask['mainTaskDn'] ?? null;
        $repeatableSchedule = $subTask['repeatableSchedule'] ?? null;

        if ($serverResults[0] == 'SUCCESS') {
            foreach ($subTask['subTask'] as $subTaskCn => $details) {
                $dn = $details['dn'];
                $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus(
                    $dn,
                    $subTaskCn,
                    '2',
                    $mainTaskDn,
                    $repeatableSchedule
                );
                $result[$dn]['mailStatus'] = 'Notification was successfully sent';
                $result[$dn]['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($mailTaskBackend[0]['dn']);
            }
        } else {
            foreach ($subTask['subTask'] as $subTaskCn => $details) {
                $dn = $details['dn'];
                $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus(
                    $dn,
                    $subTaskCn,
                    $serverResults[0],
                    $mainTaskDn,
                    $repeatableSchedule
                );
                $result[$dn]['mailStatus'] = $serverResults;
            }
        }

        return $result;
    }
}
