<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Task\TaskGateway;

class Audit implements EndpointInterface
{
    private \Orchestrator\Plugin\CoreUtils $utils;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->utils = new \Orchestrator\Plugin\CoreUtils();
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
        $auditType = $data['type'] ?? 'standard';

        if ($auditType === 'syslog') {
            $result = $this->processSyslogAuditTransformation($this->gateway->getObjectTypeTask('auditSyslog'));
        } else {
            $result = $this->processAuditDeletion($this->gateway->getObjectTypeTask('audit'));
        }

        $nonEmptyResults = $this->utils->recursiveArrayFilter($result);

        if (!empty($nonEmptyResults)) {
            return $nonEmptyResults;
        }

        return $auditType === 'syslog'
            ? ['No audit entries requiring transformation']
            : ['No standard audit entries requiring removal'];
    }

    public function processAuditDeletion(array $auditSubTasks): array
    {
        return array_values(array_map(
            fn ($task) => $this->processScheduledTask($task),
            array_filter($auditSubTasks, fn ($task) => $this->gateway->statusAndScheduleCheck($task))
        ));
    }

    private function processScheduledTask(array $task): array
    {
        $mainTaskDn = $task['fdtasksgranularmaster'][0];
        $auditMainTask = $this->getAuditMainTask($mainTaskDn);

        $repeatableSchedule = null;
        $repeatableFlag = $auditMainTask[0]['fdtasksrepeatable'][0] ?? null;
        if ($repeatableFlag !== null && strcasecmp($repeatableFlag, 'TRUE') === 0) {
            $repeatableSchedule = $auditMainTask[0]['fdtasksrepeatableschedule'][0] ?? null;
        }

        $auditRetention = $auditMainTask[0]['fdaudittasksretention'][0];

        return $this->checkAuditPassedRetention(
            $auditRetention,
            $task['dn'],
            $task['cn'][0],
            $mainTaskDn,
            $repeatableSchedule
        );
    }

    public function processSyslogAuditTransformation(array $syslogAuditSubTasks): array
    {
        $result = [];
        $path = '/var/log/fusiondirectory/';
        $this->utils->ensureDirectoryExists($path);

        foreach ($syslogAuditSubTasks as $task) {
            try {
                $mainTaskDn = null;
                $repeatableSchedule = null;

                if ($this->gateway->statusAndScheduleCheck($task)) {
                    $mainTaskDn = $task['fdtasksgranularmaster'][0];
                    $auditMainTask = $this->getAuditMainTask($mainTaskDn);

                    $repeatableFlag = $auditMainTask[0]['fdtasksrepeatable'][0] ?? null;
                    if ($repeatableFlag !== null && strcasecmp($repeatableFlag, 'TRUE') === 0) {
                        $repeatableSchedule = $auditMainTask[0]['fdtasksrepeatableschedule'][0] ?? null;
                    }

                    $prefix = $auditMainTask[0]['fdauditsyslogprefix'][0] ?? 'fd_syslog';
                    $lastProcessedTime = null;

                    $stateFile = $path . $prefix . '-last-processed.txt';
                    if (file_exists($stateFile)) {
                        $fileContent = trim(file_get_contents($stateFile));
                        if (!empty($fileContent)) {
                            $lastProcessedTime = $fileContent;
                        }
                    }

                    $filter = '(objectClass=fdAuditEvent)';
                    if ($lastProcessedTime !== null) {
                        $filter = "(&(objectClass=fdAuditEvent)(fdauditdatetime>=$lastProcessedTime))";
                    }

                    $auditEntries = $this->gateway->getLdapTasks($filter, ['*'], '', '');
                    $this->gateway->unsetCountKeys($auditEntries);

                    if (count($auditEntries) === 0) {
                        $this->updateTaskStatusSafe($task, '2', $mainTaskDn, $repeatableSchedule);
                        $result[] = ['dn' => $task['dn'], 'message' => 'No audit entries found to transform'];
                        continue;
                    }

                    $date = date('Y-m-d');
                    $filename = $path . $prefix . '-' . $date . '.log';

                    $existingAuditIds = [];
                    if (file_exists($filename)) {
                        $existingContent = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        foreach ($existingContent as $line) {
                            if (preg_match('/id="([^"]+)"/', $line, $matches)) {
                                $existingAuditIds[] = $matches[1];
                            }
                        }
                    }

                    $handle = fopen($filename, 'a');
                    if ($handle === false) {
                        throw new \RuntimeException("Could not open file: $filename");
                    }

                    $count = 0;
                    $skipped = 0;

                    foreach ($auditEntries as $entry) {
                        $auditId = $entry['fdauditid'][0] ?? 'unknown';
                        if (in_array($auditId, $existingAuditIds)) {
                            $skipped++;
                            continue;
                        }

                        $timestamp = '';
                        if (isset($entry['fdauditdatetime'][0])) {
                            $dateStr = $entry['fdauditdatetime'][0];
                            if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $dateStr, $matches)) {
                                $dt = new \DateTime("{$matches[1]}-{$matches[2]}-{$matches[3]} {$matches[4]}:{$matches[5]}:{$matches[6]}", new \DateTimeZone('UTC'));
                                $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                                $timestamp = $dt->format('M d H:i:s');
                            } else {
                                $timestamp = date('M d H:i:s');
                            }
                        } else {
                            $timestamp = date('M d H:i:s');
                        }

                        $syslogMessage = $this->createSyslogMessage($entry, $timestamp, $auditId);
                        fwrite($handle, $syslogMessage . PHP_EOL);
                        $count++;
                    }

                    fclose($handle);

                    $latestTime = null;
                    foreach ($auditEntries as $entry) {
                        if (isset($entry['fdauditdatetime'][0])) {
                            if ($latestTime === null || $entry['fdauditdatetime'][0] > $latestTime) {
                                $latestTime = $entry['fdauditdatetime'][0];
                            }
                        }
                    }

                    if ($latestTime !== null) {
                        file_put_contents($stateFile, $latestTime);
                    }

                    $this->updateTaskStatusSafe($task, '2', $mainTaskDn, $repeatableSchedule);

                    $resultMsg = "Successfully transformed $count audit entries to syslog format in $filename";
                    if ($skipped > 0) {
                        $resultMsg .= " (skipped $skipped duplicate entries)";
                    }
                    $result[] = ['dn' => $task['dn'], 'message' => $resultMsg];
                }
            } catch (\Exception $e) {
                $this->updateTaskStatusSafe($task, $e->getMessage(), $mainTaskDn, $repeatableSchedule);
                $result[] = ['dn' => $task['dn'], 'message' => 'Error transforming audit entries: ' . $e->getMessage()];
            }
        }

        return $result;
    }

    public function getAuditMainTask(string $mainTaskDn): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdAuditTasks)',
            ['fdAuditTasksRetention', 'fdAuditSyslogPrefix', 'fdTasksRepeatableSchedule', 'fdTasksRepeatable'],
            '',
            $mainTaskDn
        );
    }

    public function checkAuditPassedRetention(
        int $auditRetention,
        string $subTaskDN,
        string $subTaskCN,
        ?string $mainTaskDn = null,
        ?string $repeatableSchedule = null,
    ): array {
        $auditLib = new \FusionDirectory\Audit\AuditLib(
            $auditRetention,
            $this->returnLdapAuditEntries(),
            $this->gateway,
            $subTaskDN,
            $subTaskCN,
            $mainTaskDn,
            $repeatableSchedule
        );
        return $auditLib->checkAuditPassedRetentionOrchestrator();
    }

    public function returnLdapAuditEntries(): array
    {
        $audit = $this->gateway->getLdapTasks('(objectClass=fdAuditEvent)', ['fdAuditDateTime'], '', '');
        $this->gateway->unsetCountKeys($audit);
        return $audit;
    }

    private function createSyslogMessage(array $entry, string $timestamp, string $auditId): string
    {
        $hostname = $entry['fdauditauthorip'][0] ?? gethostname();
        $author = $entry['fdauditauthordn'][0] ?? 'unknown';
        $action = $entry['fdauditaction'][0] ?? 'unknown';
        $objectType = $entry['fdauditobjecttype'][0] ?? '';
        $object = $entry['fdauditobject'][0] ?? '';
        $auditResult = $entry['fdauditresult'][0] ?? '';

        $syslogMessage = "<local4.info>$timestamp $hostname FusionDirectory-Audit: ";
        $syslogMessage .= "id=\"$auditId\" ";
        $syslogMessage .= "author=\"$author\" ";
        $syslogMessage .= "action=\"$action\" ";

        if (!empty($objectType)) {
            $syslogMessage .= "objectType=\"$objectType\" ";
        }
        if (!empty($object)) {
            $syslogMessage .= "object=\"$object\" ";
        }
        if (!empty($auditResult)) {
            $syslogMessage .= "result=\"$auditResult\" ";
        }
        if (isset($entry['fdauditattributes'][0])) {
            $syslogMessage .= "changes=\"" . $entry['fdauditattributes'][0] . "\" ";
        }

        return $syslogMessage;
    }

    private function updateTaskStatusSafe(array $task, string $status, ?string $mainTaskDn, ?string $repeatableSchedule): void
    {
        if ($repeatableSchedule !== null && $mainTaskDn !== null) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $status, $mainTaskDn, $repeatableSchedule);
        } elseif ($mainTaskDn !== null) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $status, $mainTaskDn);
        } else {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $status);
        }
    }
}
