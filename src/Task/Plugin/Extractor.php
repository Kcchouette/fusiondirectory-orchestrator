<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Plugin\CoreUtils;
use Orchestrator\Plugin\MailUtils;
use Orchestrator\Task\TaskGateway;

class Extractor implements EndpointInterface
{
    private CoreUtils $utils;
    private MailUtils $mailUtils;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->utils = new CoreUtils();
        $this->mailUtils = new MailUtils();
    }

    public function processEndPointGet(): array
    {
        return $this->gateway->getObjectTypeTask('extract');
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
        $result = [];
        $extractTasks = $this->gateway->getObjectTypeTask('extract');
        $processedAnyTask = false;

        $path = $data['path'] ?? '/srv/orchestrator/';

        foreach ($extractTasks as $task) {
            try {
                $mainTaskDn = null;
                $repeatableSchedule = null;

                if (!$this->gateway->statusAndScheduleCheck($task)) {
                    continue;
                }

                if (!isset($task['fdtasksgranulardn'][0]) || $task['fdtasksgranulardn'][0] !== 'bulkExtractorTask') {
                    continue;
                }

                $processedAnyTask = true;

                $mainTaskDn = $task['fdtasksgranularmaster'][0];
                $mainTaskConfig = $this->getExtractMainTaskConfig($mainTaskDn);

                $isRepeatableFlag = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? null;
                if ($isRepeatableFlag !== null && strcasecmp($isRepeatableFlag, 'TRUE') === 0) {
                    $repeatableSchedule = $mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? null;
                }

                $userDnListRaw = $mainTaskConfig[0]['fdextractortasklistofdn'] ?? [];
                $userDnList = [];

                if (is_array($userDnListRaw)) {
                    $userDnList = $userDnListRaw;
                    unset($userDnList['count']);
                } elseif (is_string($userDnListRaw) && !empty($userDnListRaw)) {
                    $userDnList = [$userDnListRaw];
                }

                if (empty($userDnList)) {
                    $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
                    $result[$task['dn']]['result'] = 'No user DNs to process.';
                    continue;
                }

                $this->utils->ensureDirectoryExists($path);

                $mainTaskCn = $this->getMainTaskCn($mainTaskDn);
                $date = date('Y-m-d_H');
                $uniqueId = substr(md5((string) microtime(true)), 0, 8);

                $filename = isset($data['filename'])
                    ? $path . $data['filename'] . '_' . $date . '_' . $uniqueId . '.csv'
                    : $path . $mainTaskCn . '_' . $date . '_' . $uniqueId . '.csv';

                $allUserAttributes = [];
                $errors = [];

                foreach ($userDnList as $userDn) {
                    if (empty($userDn)) {
                        continue;
                    }
                    try {
                        $userAttributes = $this->getUserAttributes($userDn, $mainTaskConfig);
                        if (!empty($userAttributes)) {
                            $allUserAttributes[] = $userAttributes[0];
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Error fetching attributes for DN '$userDn': " . $e->getMessage();
                    }
                }

                if (empty($allUserAttributes)) {
                    $finalMessage = 'No user attributes could be extracted.';
                    if (!empty($errors)) {
                        $finalMessage .= ' Errors: ' . implode('; ', $errors);
                    }
                    $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
                    $result[$task['dn']]['result'] = $finalMessage;
                    continue;
                }

                $success = $this->extractToFileBatch($allUserAttributes, $filename, 'csv');

                if ($success) {
                    $mainTaskDetails = $this->gateway->getLdapTasks(
                        '(objectClass=fdExtractorTasks)',
                        ['fdExtractorEmailSender', 'fdExtractorListOfRecipientsMails'],
                        '',
                        $mainTaskDn
                    );
                    $sender = $mainTaskDetails[0]['fdextractoremailsender'][0] ?? '';
                    $recipients = $mainTaskDetails[0]['fdextractorlistofrecipientsmails'] ?? [];
                    $this->gateway->unsetCountKeys($recipients);

                    $finalMessage = $this->getFinalMessage($filename, $task, $recipients, $sender, $errors, $mainTaskDn, $repeatableSchedule);
                    $result[$task['dn']]['result'] = $finalMessage;
                } else {
                    $finalMessage = "Failed to write batch data to $filename.";
                    $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage);
                    $result[$task['dn']]['result'] = $finalMessage;
                }
            } catch (\Exception $e) {
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn, $repeatableSchedule);
                $result[$task['dn']]['result'] = 'Error: ' . $e->getMessage();
            }
        }

        if (!$processedAnyTask && empty($result)) {
            $result['status'] = 'No tasks to process for extractor.';
        }

        return $result;
    }

    private function getFinalMessage(string $filename, array $task, array $recipients, $sender, array $errors, $mainTaskDn, $repeatableSchedule): string
    {
        $subject = 'FusionDirectory Extractor - Export file';
        $body = "Your requested extract is attached.\n\nFile: $filename";
        $attachments = [[
            'cn' => basename($filename),
            'content' => file_get_contents($filename),
        ]];

        if (empty($sender) || empty($recipients)) {
            $finalMessage = "Batch extraction successful to $filename. Email not sent: sender or recipient missing.";
            if (!empty($errors)) {
                $finalMessage .= ' Errors: ' . implode('; ', $errors);
            }
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
        } else {
            $mailSentResult = $this->mailUtils->sendMail($sender, null, $recipients, $body, null, $subject, null, $attachments);

            if ($mailSentResult[0] == 'SUCCESS') {
                $finalMessage = "Batch extraction successful to $filename. Email sent.";
                if (!empty($errors)) {
                    $finalMessage .= ' Errors: ' . implode('; ', $errors);
                }
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
            } else {
                $finalMessage = "Batch extraction successful to $filename, but email failed: " . $mailSentResult[0];
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage, $mainTaskDn, $repeatableSchedule);
            }
        }

        return $finalMessage;
    }

    private function getExtractMainTaskConfig(string $mainTaskDn): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdExtractorTasks)',
            [
                'fdExtractorTaskFormat', 'cn', 'fdExtractorTaskListOfDN',
                'fdExtractorTaskAttributes', 'fdTasksRepeatableSchedule', 'fdTasksRepeatable',
            ],
            '',
            $mainTaskDn
        );
    }

    private function getUserAttributes(string $userDn, array $mainTaskConfig): array
    {
        $attributesToFetch = ['*'];

        if (!empty($mainTaskConfig[0]['fdextractortaskattributes'])) {
            $attrList = $mainTaskConfig[0]['fdextractortaskattributes'];
            $this->gateway->unsetCountKeys($attrList);

            if (is_array($attrList) && !(count($attrList) === 1 && strtoupper($attrList[0]) === 'ALL')) {
                $attributesToFetch = [];
                foreach ($attrList as $attr) {
                    if (is_string($attr)) {
                        $attributesToFetch[] = $attr;
                    }
                }
            } elseif (is_string($attrList) && strtoupper($attrList) !== 'ALL') {
                $attributesToFetch = [$attrList];
            }
        }

        $userData = $this->gateway->getLdapTasks('(objectClass=inetOrgPerson)', $attributesToFetch, '', $userDn);
        $this->gateway->unsetCountKeys($userData);
        return $userData;
    }

    private function extractToFileBatch(array $allUserAttributes, string $filename, string $format): bool
    {
        if (empty($allUserAttributes)) {
            return true;
        }

        if (strtolower($format) !== 'csv') {
            throw new \InvalidArgumentException("Unsupported format '$format'. Only CSV is supported.");
        }

        return $this->exportToCsvBatch($allUserAttributes, $filename);
    }

    private function exportToCsvBatch(array $allUserAttributes, string $filename): bool
    {
        $allColumns = [];
        $allUserData = [];

        foreach ($allUserAttributes as $user) {
            foreach ($user as $attribute => $values) {
                if (is_string($attribute) && $attribute !== 'count') {
                    $allColumns[$attribute] = true;
                }
            }
        }

        foreach ($allUserAttributes as $user) {
            $userData = [];
            foreach (array_keys($allColumns) as $column) {
                if (isset($user[$column])) {
                    $userData[$column] = is_array($user[$column]) ? implode(';', $user[$column]) : $user[$column];
                } else {
                    $userData[$column] = '';
                }
            }
            $allUserData[] = $userData;
        }

        if (empty($allUserData)) {
            return true;
        }

        $finalColumns = array_keys($allColumns);

        $handle = fopen($filename, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Could not open file for writing: $filename");
        }

        try {
            fputcsv($handle, $finalColumns, escape: '\\');
            foreach ($allUserData as $row) {
                fputcsv($handle, $row, escape: '\\');
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    private function getMainTaskCn(string $mainTaskDn): string
    {
        $mainTask = $this->gateway->getLdapTasks('(objectClass=fdTasks)', ['cn'], '', $mainTaskDn);
        return $mainTask[0]['cn'][0] ?? 'extract';
    }
}
