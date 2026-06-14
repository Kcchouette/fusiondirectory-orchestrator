<?php

declare(strict_types=1);

namespace Orchestrator\Task;

use Orchestrator\Ldap\Backend;
use Orchestrator\Ldap\Ldap;

class TaskGateway
{
    public \LDAP\Connection $ds;
    public Backend $fdConfiguration;
    public string $templateBranch;

    public function __construct(
        private readonly Ldap $ldap,
    ) {
        $this->ds = $this->ldap->getConnection();
    }

    public function getTask(?string $objectType): array
    {
        switch ($objectType) {
            case 'removeSubTasks':
            case 'activateCyclicTasks':
            case 'restartFailedTasks':
                $listTasks = ['Generic tasks execution'];
                break;

            case 'tasks':
                $listTasks = $this->getLdapTasks('(objectClass=fdTasks)', ['cn', 'objectClass']);
                break;

            case $objectType:
                $listTasks = $this->getLdapTasks(
                    "(&(objectClass=fdTasksGranular)(fdtasksgranulartype=$objectType))"
                );
                $this->unsetCountKeys($listTasks);
                break;

            default:
                $listTasks = [];
                break;
        }

        return $listTasks;
    }

    public function statusAndScheduleCheck(array $task): bool
    {
        return $task['fdtasksgranularstatus'][0] == 1
            && $this->verifySchedule($task['fdtasksgranularschedule'][0]);
    }

    public function unsetCountKeys(array &$array): void
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->unsetCountKeys($value);
            } elseif ($key === 'count') {
                unset($array[$key]);
            }
        }
        unset($value);
    }

    public function removeSubTask($subTaskDn)
    {
        try {
            $result = ldap_delete($this->ds, $subTaskDn);
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    public function removeCompletedTasks(): array
    {
        $result = [];
        $subTasksCompleted = $this->getLdapTasks(
            "(&(objectClass=fdTasksGranular)(|(fdTasksGranularStatus=2)(fdTasksGranularStatus=3)))",
            ['dn']
        );
        $this->unsetCountKeys($subTasksCompleted);

        if (!empty($subTasksCompleted)) {
            foreach ($subTasksCompleted as $subTasks) {
                $result[$subTasks['dn']]['result'] = $this->removeSubTask($subTasks['dn']);
            }
        } else {
            $result[] = 'No completed or nothing-to-process sub-tasks were removed.';
        }

        return $result;
    }

    public function activateCyclicTasks(): array
    {
        $result = [];
        $tasks = $this->getLdapTasks(
            "(&(objectClass=fdTasks)(fdTasksRepeatable=TRUE))",
            ['dn', 'fdTasksRepeatableSchedule', 'fdTasksLastActivation', 'fdTasksScheduleDate']
        );
        $this->unsetCountKeys($tasks);

        if (!empty($tasks)) {
            $webservice = new \FusionDirectory\Rest\WebServiceCall(
                $_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/login',
                'POST'
            );
            $webservice->setCurlSettings();

            $now = new \DateTime('now');

            foreach ($tasks as $task) {
                $schedule = \DateTime::createFromFormat('YmdHis', $task['fdtasksscheduledate'][0]);

                if ($schedule <= $now) {
                    if (empty($task['fdtaskslastactivation'][0])) {
                        $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                    } elseif (!empty($task['fdtasksrepeatableschedule'][0])) {
                        $lastActivation = new \DateTime($task['fdtaskslastactivation'][0]);
                        $interval = $now->diff($lastActivation);

                        $scheduleStr = $task['fdtasksrepeatableschedule'][0];
                        switch ($scheduleStr) {
                            case 'Yearly':
                                if ($interval->y >= 1) {
                                    $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                                }
                                break;
                            case 'Monthly':
                                if ($interval->m >= 1 || $interval->y >= 1) {
                                    $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                                }
                                break;
                            case 'Weekly':
                                if ($interval->days >= 7) {
                                    $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                                }
                                break;
                            case 'Daily':
                                if ($interval->days >= 1 || $interval->m >= 1 || $interval->y >= 1) {
                                    $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                                }
                                break;
                            case 'Hourly':
                                if ($interval->h >= 1 || $interval->days >= 1) {
                                    $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                                }
                                break;
                            default:
                                if (str_starts_with($scheduleStr, 'Minutes:')) {
                                    $minutes = $this->parseMinuteSchedule($scheduleStr);
                                    if ($minutes !== null) {
                                        $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                                        if ($totalMinutes >= $minutes) {
                                            $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                                        }
                                    }
                                }
                                break;
                        }
                    }
                } else {
                    $result[$task['dn']]['Status'] = 'This cyclic task has yet to reach its next activation cycle.';
                }
            }
        } else {
            $result[] = 'No tasks require activation.';
        }

        return $result;
    }

    public function verifySchedule(string $schedule): bool
    {
        $currentDateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $scheduledDateTime = \FusionDirectory\Ldap\GeneralizedTime::fromString($schedule);

        return $scheduledDateTime < $currentDateTime;
    }

    public function getLdapTasks(
        string $filter = '',
        array $attrs = [],
        ?string $attachmentsCN = null,
        ?string $base = null,
    ): array {
        $this->fdConfiguration = new Backend();
        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $this->templateBranch = $fdConfigAttributes[0]['fdMailTemplateRDN'][0];

        $result = [];

        if (empty($base)) {
            $base = $_ENV['LDAP_BASE'];
        }

        if (!empty($attachmentsCN)) {
            $base = 'cn=' . $attachmentsCN . ',' . $this->templateBranch . ',' . $base;
        }

        try {
            $sr = ldap_search($this->ds, $base, $filter, $attrs);
            $info = ldap_get_entries($this->ds, $sr);
        } catch (\Exception $e) {
            throw new \Exception("Ldap Error: $e");
        }

        if (!empty($info) && $info['count'] >= 1) {
            return $info;
        }

        return $result;
    }

    public function updateTaskStatus(
        string $dn,
        string $cn,
        string $status,
        ?string $mainTaskDn = null,
        ?string $repeatableSchedule = null,
    ) {
        $ldapEntry = [];

        if (!empty($dn)) {
            $ldapEntry['cn'] = $cn;
        }

        $currentDateTime = new \DateTime('now', new \DateTimeZone('UTC'));

        $ldapEntry['fdTasksGranularStatus'] = $status;
        $ldapEntry['fdTasksGranularLastExec'] = \FusionDirectory\Ldap\GeneralizedTime::toString($currentDateTime);

        if (!empty($repeatableSchedule)) {
            $nextExecTime = $this->calculateNextExecutionTime($repeatableSchedule, $currentDateTime);
            if ($nextExecTime !== null) {
                $ldapEntry['fdTasksGranularNextExec'] = $nextExecTime;
            }
        }

        try {
            $result = ldap_modify($this->ds, $dn, $ldapEntry);

            if ($result) {
                if ($mainTaskDn) {
                    $this->updateMainTaskLastExec($mainTaskDn, $currentDateTime);
                    if (isset($ldapEntry['fdTasksGranularNextExec'])) {
                        $this->updateMainTaskNextExec($mainTaskDn, $ldapEntry['fdTasksGranularNextExec']);
                    }
                } else {
                    $subtask = $this->getLdapTasks(
                        "(&(objectClass=fdTasksGranular)(cn=$cn))",
                        ['fdTasksGranularMaster']
                    );
                    if (!empty($subtask) && isset($subtask[0]['fdtasksgranularmaster'][0])) {
                        $this->updateMainTaskLastExec($subtask[0]['fdtasksgranularmaster'][0], $currentDateTime);
                        if (isset($ldapEntry['fdTasksGranularNextExec'])) {
                            $this->updateMainTaskNextExec(
                                $subtask[0]['fdtasksgranularmaster'][0],
                                $ldapEntry['fdTasksGranularNextExec']
                            );
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    public function getObjectTypeTask(string $objectType): array
    {
        $task = $this->getTask($objectType);
        if (!$task) {
            throw new \RuntimeException("Task object type: $objectType not found", 404);
        }

        return $task;
    }

    public function updateLastMailExecTime(string $dn)
    {
        $currentDateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $ldapEntry['fdTasksConfLastExecTime'] = \FusionDirectory\Ldap\GeneralizedTime::toString($currentDateTime);

        try {
            $result = ldap_modify($this->ds, $dn, $ldapEntry);
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    public function updateMainTaskLastExec(string $mainTaskDn, \DateTime $timestampDateTime)
    {
        $ldapEntry['fdTasksLastExec'] = \FusionDirectory\Ldap\GeneralizedTime::toString($timestampDateTime);

        try {
            $result = ldap_modify($this->ds, $mainTaskDn, $ldapEntry);
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    public function updateMainTaskNextExec(string $mainTaskDn, string $timestamp)
    {
        $ldapEntry['fdTasksNextExec'] = $timestamp;

        try {
            $result = ldap_modify($this->ds, $mainTaskDn, $ldapEntry);
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    private function calculateNextExecutionTime(string $repeatableSchedule, \DateTime $currentDateTime): ?string
    {
        $nextExecutionTime = clone $currentDateTime;

        switch ($repeatableSchedule) {
            case 'Yearly':
                $nextExecutionTime->modify('+1 year');
                break;
            case 'Monthly':
                $nextExecutionTime->modify('+1 month');
                break;
            case 'Weekly':
                $nextExecutionTime->modify('+1 week');
                break;
            case 'Daily':
                $nextExecutionTime->modify('+1 day');
                break;
            case 'Hourly':
                $nextExecutionTime->modify('+1 hour');
                break;
            default:
                if (str_starts_with($repeatableSchedule, 'Minutes:')) {
                    $minutes = $this->parseMinuteSchedule($repeatableSchedule);
                    if ($minutes !== null) {
                        $nextExecutionTime->modify("+{$minutes} minutes");
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
        }

        return \FusionDirectory\Ldap\GeneralizedTime::toString($nextExecutionTime);
    }

    private function parseMinuteSchedule(string $scheduleStr): ?int
    {
        if (preg_match('/^Minutes:(\d{2})$/', $scheduleStr, $m)) {
            $val = intval($m[1], 10);
            if ($val >= 0 && $val <= 59) {
                return $val;
            }
        }

        return null;
    }

    public function restartFailedSubtasks(?string $taskName = null): array
    {
        $result = [
            'updated' => [],
            'errors' => [],
        ];

        $filterParts = [
            '(&(objectClass=fdTasksGranular)',
            '(!(fdTasksGranularStatus=1))',
            '(!(fdTasksGranularStatus=2))',
            '(!(fdTasksGranularStatus=3))',
        ];

        if (!empty($taskName)) {
            $mainTaskDn = $this->resolveMainTaskDnByName($taskName);
            if ($mainTaskDn === null) {
                return ['errors' => ["Main task with cn '$taskName' not found"]];
            }
            $filterParts[] = '(fdTasksGranularMaster=' . $mainTaskDn . ')';
        }

        $filterParts[] = ')';
        $filter = implode('', $filterParts);

        $subtasks = $this->getLdapTasks($filter, ['dn', 'cn']);
        $this->unsetCountKeys($subtasks);

        if (empty($subtasks)) {
            return ['message' => 'No failed subtasks found to restart.'];
        }

        foreach ($subtasks as $entry) {
            if (empty($entry['dn'])) {
                continue;
            }
            $dn = $entry['dn'];
            $cn = $entry['cn'][0] ?? basename($dn);

            $ldapEntry = [
                'fdTasksGranularStatus' => '1',
            ];

            try {
                $ok = ldap_modify($this->ds, $dn, $ldapEntry);
                if ($ok) {
                    $result['updated'][] = $dn;
                } else {
                    $result['errors'][] = ['dn' => $dn, 'error' => 'ldap_modify returned false'];
                }
            } catch (\Exception $e) {
                $result['errors'][] = ['dn' => $dn, 'error' => (string) $e];
            }
        }

        return $result;
    }

    private function resolveMainTaskDnByName(string $name): ?string
    {
        $filter = "(&(objectClass=fdTasks)(cn=$name))";
        $entries = $this->getLdapTasks($filter, ['dn']);
        $this->unsetCountKeys($entries);

        if (!empty($entries) && !empty($entries[0]['dn'])) {
            return $entries[0]['dn'];
        }

        return null;
    }
}
