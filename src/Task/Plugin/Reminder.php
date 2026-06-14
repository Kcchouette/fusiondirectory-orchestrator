<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Plugin\ReminderTokenUtils;
use Orchestrator\Plugin\MailUtils;
use Orchestrator\Task\TaskGateway;

class Reminder implements EndpointInterface
{
    private ReminderTokenUtils $reminderTokenUtils;
    private MailUtils $mailUtils;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->reminderTokenUtils = new ReminderTokenUtils();
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
        return $this->processReminder($this->gateway->getObjectTypeTask('reminder'));
    }

    public function processReminder(array $reminderSubTasks): array
    {
        $result = [];
        $reminders = [];

        foreach ($reminderSubTasks as $task) {
            if ($this->gateway->statusAndScheduleCheck($task)) {
                $mainTaskDn = $task['fdtasksgranularmaster'][0];
                $remindersMainTask = $this->getRemindersMainTask($mainTaskDn);
                $this->gateway->unsetCountKeys($remindersMainTask);

                $repeatableSchedule = null;
                $repeatableFlag = $remindersMainTask[0]['fdtasksrepeatable'][0] ?? null;
                if ($repeatableFlag !== null && strcasecmp($repeatableFlag, 'TRUE') === 0) {
                    $repeatableSchedule = $remindersMainTask[0]['fdtasksrepeatableschedule'][0] ?? null;
                }

                $mailOfTheReminded = $this->getEmailFromReminder($task['fdtasksgranulardn'][0]);
                $mailTemplateForm = $this->generateMainTaskMailTemplate($remindersMainTask, $mailOfTheReminded);
                $monitoredResources = $this->getMonitoredResources($remindersMainTask[0]);

                if ($monitoredResources['resource'][0] === 'NONE' && $monitoredResources['prolongation'] === 'FALSE') {
                    $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
                        $task['dn'], $task['cn'][0], '3', $mainTaskDn, $repeatableSchedule
                    );
                    $result[$task['dn']]['Message'] = 'No reminder triggers found, nothing to process!';
                }

                if ($monitoredResources['resource'][0] !== 'NONE' && $monitoredResources['prolongation'] === 'FALSE') {
                    if ($this->supannAboutToExpire($task['fdtasksgranulardn'][0], $monitoredResources, $task['fdtasksgranularhelper'][0])) {
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['dn'] = $task['dn'];
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
                        $reminders[$mainTaskDn]['repeatableSchedule'] = $repeatableSchedule;
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['mail'] = $mailTemplateForm;
                    } else {
                        $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
                            $task['dn'], $task['cn'][0], '3', $mainTaskDn, $repeatableSchedule
                        );
                        $result[$task['dn']]['Message'] = 'Account not about to expire!';
                    }
                }

                if ($monitoredResources['resource'][0] !== 'NONE' && $monitoredResources['prolongation'] === 'TRUE') {
                    if ($this->supannAboutToExpire($task['fdtasksgranulardn'][0], $monitoredResources, $task['fdtasksgranularhelper'][0])) {
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['dn'] = $task['dn'];
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
                        $reminders[$mainTaskDn]['repeatableSchedule'] = $repeatableSchedule;

                        $tokenExpire = $this->reminderTokenUtils->getTokenExpiration(
                            $task['fdtasksgranularhelper'][0],
                            $remindersMainTask[0]['fdtasksreminderfirstcall'][0],
                            $remindersMainTask[0]['fdtasksremindersecondcall'][0]
                        );
                        $token = $this->reminderTokenUtils->generateToken(
                            $task['fdtasksgranulardn'][0],
                            $tokenExpire,
                            $this->gateway
                        );
                        $tokenMailTemplateForm = $this->reminderTokenUtils->generateTokenUrl(
                            $token,
                            $mailTemplateForm,
                            $mainTaskDn
                        );
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['mail'] = $tokenMailTemplateForm;
                    } else {
                        $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
                            $task['dn'], $task['cn'][0], '3', $mainTaskDn, $repeatableSchedule
                        );
                    }
                }

                if ($monitoredResources['resource'][0] === 'NONE' && $monitoredResources['prolongation'] === 'TRUE') {
                    if ($this->posixAboutToExpire($task['fdtasksgranulardn'][0], $task['fdtasksgranularhelper'][0])) {
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['dn'] = $task['dn'];
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
                        $reminders[$mainTaskDn]['repeatableSchedule'] = $repeatableSchedule;

                        $tokenExpire = $this->reminderTokenUtils->getTokenExpiration(
                            $task['fdtasksgranularhelper'][0],
                            $remindersMainTask[0]['fdtasksreminderfirstcall'][0],
                            $remindersMainTask[0]['fdtasksremindersecondcall'][0]
                        );
                        $token = $this->reminderTokenUtils->generateToken(
                            $task['fdtasksgranulardn'][0],
                            $tokenExpire,
                            $this->gateway
                        );
                        $tokenMailTemplateForm = $this->reminderTokenUtils->generateTokenUrl(
                            $token,
                            $mailTemplateForm,
                            $mainTaskDn
                        );
                        $reminders[$mainTaskDn]['subTask'][$task['cn'][0]]['mail'] = $tokenMailTemplateForm;
                    } else {
                        $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
                            $task['dn'], $task['cn'][0], '3', $mainTaskDn, $repeatableSchedule
                        );
                    }
                }
            }
        }

        if (!empty($reminders)) {
            $result[] = $this->sendRemindersMail($reminders);
        }

        return $result;
    }

    private function posixAboutToExpire(string $dn, int $days): bool
    {
        $userShadowExpire = $this->retrieveUserPosix($dn);

        if (!empty($userShadowExpire)) {
            $today = new \DateTime();
            $epoch = new \DateTime('1970-01-01');
            $epoch->add(new \DateInterval("P{$userShadowExpire}D"));
            $interval = $today->diff($epoch);

            if ($interval->invert == 0) {
                if (($interval->days < $days) || (($interval->days == $days) && ($interval->h == 0))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function retrieveUserPosix(string $dn): string
    {
        $result = '';
        $userPosix = $this->gateway->getLdapTasks('(objectClass=shadowAccount)', ['shadowExpire'], '', $dn);
        $this->gateway->unsetCountKeys($userPosix);

        if (!empty($userPosix[0]['shadowexpire'][0])) {
            $result = $userPosix[0]['shadowexpire'][0];
        }

        return $result;
    }

    private function getEmailFromReminder(string $dn): string
    {
        $email = $this->gateway->getLdapTasks('(objectClass=gosaMailAccount)', ['mail'], '', $dn);
        $this->gateway->unsetCountKeys($email);

        return !empty($email[0]['mail'][0]) ? $email[0]['mail'][0] : 'FALSE';
    }

    private function supannAboutToExpire(string $dn, array $monitoredResources, int $days): bool
    {
        $supannResources = $this->retrieveSupannResources($dn);
        $matchedResource = $this->verifySupannState($monitoredResources, $supannResources);

        if ($matchedResource) {
            $dnSupannDateObject = $this->retrieveDateFromSupannResourceState(
                $supannResources['supannressourceetatdate'],
                $matchedResource
            );

            if ($dnSupannDateObject !== false) {
                $today = new \DateTime();
                $interval = $today->diff($dnSupannDateObject);

                if ($interval->invert == 0) {
                    if (($interval->days < $days) || (($interval->days == $days) && ($interval->h == 0))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function retrieveSupannResources(string $dn): array
    {
        $supannResources = $this->gateway->getLdapTasks(
            '(objectClass=supannPerson)',
            ['supannRessourceEtatDate', 'supannRessourceEtat'],
            '',
            $dn
        );
        $this->gateway->unsetCountKeys($supannResources);

        return !empty($supannResources) ? $supannResources[0] : [];
    }

    private function getMonitoredResources(array $remindersMainTask): array
    {
        $monitoredResourcesArray = [
            'resource' => $remindersMainTask['fdtasksreminderresource'],
            'state' => $remindersMainTask['fdtasksreminderstate'],
            'subState' => $remindersMainTask['fdtasksremindersubstate'] ?? null,
        ];

        if (isset($remindersMainTask['fdtasksreminderaccountprolongation'][0])
            && $remindersMainTask['fdtasksreminderaccountprolongation'][0] === 'TRUE'
        ) {
            if (isset($remindersMainTask['fdtasksremindernextresource'])) {
                $monitoredResourcesArray['nextResource'] = $remindersMainTask['fdtasksremindernextresource'];
                $monitoredResourcesArray['nextState'] = $remindersMainTask['fdtasksremindernextstate'];
                $monitoredResourcesArray['nextSubState'] = $remindersMainTask['fdtasksremindernextsubstate'] ?? null;
            }
            $monitoredResourcesArray['fdTasksReminderPosix'] = $remindersMainTask['fdtasksreminderposix'] ?? false;
        }

        $monitoredResourcesArray['prolongation'] = $remindersMainTask['fdtasksreminderaccountprolongation'][0] ?? false;

        return $monitoredResourcesArray;
    }

    private function verifySupannState(array $reminderSupann, array $dnSupann): string
    {
        $monitoredSupannState = '{' . $reminderSupann['resource'][0] . '}' . $reminderSupann['state'][0];
        if (!empty($reminderSupann['subState'][0])) {
            $monitoredSupannState .= ':' . $reminderSupann['subState'][0];
        }

        if (!empty($dnSupann['supannressourceetat'])) {
            foreach ($dnSupann['supannressourceetat'] as $resource) {
                if ($monitoredSupannState === $resource) {
                    return $resource;
                }
            }
        }

        return '';
    }

    private function retrieveDateFromSupannResourceState(array $supannEtatDate, string $resource)
    {
        $pattern = '/^' . preg_quote($resource, '/') . '(:|:::)?.*/';
        $matchFound = null;

        foreach ($supannEtatDate as $resourceWithDate) {
            if (preg_match($pattern, $resourceWithDate)) {
                $matchFound = $resourceWithDate;
                break;
            }
        }

        if ($matchFound === null) {
            return false;
        }

        preg_match('/(\d{8})$/', $matchFound, $matches);

        return !empty($matches) ? \DateTime::createFromFormat('Ymd', $matches[0]) : false;
    }

    public function getRemindersMainTask(string $mainTaskDn): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdTasksReminder)',
            [
                'fdTasksReminderListOfRecipientsMails', 'fdTasksReminderResource', 'fdTasksReminderState',
                'fdTasksReminderPosix', 'fdTasksReminderMailTemplate', 'fdTasksReminderSupannNewEndDate',
                'fdTasksReminderRecipientsMembers', 'fdTasksReminderEmailSender',
                'fdTasksReminderAccountProlongation', 'fdTasksReminderMembers',
                'fdTasksReminderNextResource', 'fdTasksReminderNextState', 'fdTasksReminderNextSubState',
                'fdTasksReminderSubState', 'fdTasksReminderFirstCall', 'fdTasksReminderSecondCall',
                'fdTasksRepeatableSchedule', 'fdTasksRepeatable',
            ],
            '',
            $mainTaskDn
        );
    }

    private function generateMainTaskMailTemplate(array $mainTask, string $remindedEmail): array
    {
        $sender = $mainTask[0]['fdtasksreminderemailsender'][0];
        $mailTemplateName = $mainTask[0]['fdtasksremindermailtemplate'][0];

        $mailInfos = $this->gateway->getLdapTasks(
            '(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))',
            [],
            $mailTemplateName
        );
        $this->gateway->unsetCountKeys($mailInfos);

        $mailContent = $mailInfos[0];

        if (!empty($mainTask[0]['fdtasksreminderlistofrecipientsmails'])) {
            $recipients = array_merge($mainTask[0]['fdtasksreminderlistofrecipientsmails'], [$remindedEmail]);
            $this->gateway->unsetCountKeys($recipients);
            $recipients = array_unique($recipients);
        } else {
            $recipients = $remindedEmail;
        }

        $mailMacros = $mailContent['fdmailtemplatemacro'] ?? [];

        return [
            'setFrom' => $sender,
            'recipients' => $recipients,
            'body' => $this->mailUtils->replaceMacros($this->gateway, $recipients, $mailContent['fdmailtemplatebody'][0], $mailMacros),
            'signature' => $mailContent['fdmailtemplatesignature'][0] ?? null,
            'subject' => $mailContent['fdmailtemplatesubject'][0],
            'receipt' => $mailContent['fdmailtemplatereadreceipt'][0],
        ];
    }

    protected function sendRemindersMail(array $reminders): array
    {
        $result = [];
        $fdTasksConf = $this->gateway->getLdapTasks(
            '(objectClass=fdTasksConf)',
            ['fdTasksConfLastExecTime', 'fdTasksConfIntervalEmails', 'fdTasksConfMaxEmails']
        );
        $maxMailsConfig = $fdTasksConf[0]['fdtasksconfmaxemails'][0] ?? 50;
        $maxMailsIncrement = 0;

        foreach ($reminders as $mainTaskDn => $reminder) {
            $repeatableSchedule = $reminder['repeatableSchedule'] ?? null;

            foreach ($reminder['subTask'] as $subTaskCn => $mailDetails) {
                if (!is_array($mailDetails['mail']['recipients'])) {
                    $mailDetails['mail']['recipients'] = [$mailDetails['mail']['recipients']];
                }

                $numberOfRecipients = count($mailDetails['mail']['recipients']);

                $mailSentResult = $this->mailUtils->sendMail(
                    $mailDetails['mail']['setFrom'],
                    null,
                    $mailDetails['mail']['recipients'],
                    $mailDetails['mail']['body'],
                    $mailDetails['mail']['signature'],
                    $mailDetails['mail']['subject'],
                    $mailDetails['mail']['receipt'],
                    null
                );

                $taskInfo = [
                    'mainTaskDn' => $mainTaskDn,
                    'repeatableSchedule' => $repeatableSchedule,
                    'subTask' => [$subTaskCn => $mailDetails],
                ];

                $result[] = $this->processMailResponseAndUpdateTasks($mailSentResult, $taskInfo, $fdTasksConf);

                $maxMailsIncrement += $numberOfRecipients;
                if ($maxMailsIncrement == $maxMailsConfig) {
                    break;
                }
            }
        }

        return $result;
    }

    protected function processMailResponseAndUpdateTasks(array $serverResults, array $taskInfo, array $mailTaskBackend): array
    {
        $result = [];
        $mainTaskDn = $taskInfo['mainTaskDn'];
        $repeatableSchedule = $taskInfo['repeatableSchedule'];

        if ($serverResults[0] == 'SUCCESS') {
            foreach ($taskInfo['subTask'] as $subTask => $details) {
                $dn = $details['dn'];
                $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus(
                    $dn, $subTask, '2', $mainTaskDn, $repeatableSchedule
                );
                $result[$dn]['mailStatus'] = 'Reminder was successfully sent';
                $result[$dn]['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($mailTaskBackend[0]['dn']);
            }
        } else {
            foreach ($taskInfo['subTask'] as $subTask => $details) {
                $dn = $details['dn'];
                $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus(
                    $dn, $subTask, $serverResults[0], $mainTaskDn, $repeatableSchedule
                );
                $result[$dn]['mailStatus'] = $serverResults;
            }
        }

        return $result;
    }
}
