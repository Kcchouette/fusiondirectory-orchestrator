<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Task\TaskGateway;

class LifeCycle implements EndpointInterface
{
    private \Orchestrator\Plugin\CoreUtils $coreUtils;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->coreUtils = new \Orchestrator\Plugin\CoreUtils();
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
        return $this->processLifeCycleTasks($this->gateway->getObjectTypeTask('lifeCycle'));
    }

    private function getLifeCycleBehaviorFromMainTask(string $taskDN): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdTasksLifeCycle)',
            [
                'fdTasksLifeCyclePreResource', 'fdTasksLifeCyclePreState', 'fdTasksLifeCyclePreSubState',
                'fdTasksLifeCyclePostResource', 'fdTasksLifeCyclePostState', 'fdTasksLifeCyclePostSubState',
                'fdTasksLifeCyclePostEndDate', 'fdTasksLifeCycleRegexPattern',
                'fdTasksLifeCycleEnableAccountClosure', 'fdTasksRepeatableSchedule', 'fdTasksRepeatable',
            ],
            '',
            $taskDN
        );
    }

    protected function shouldProcessAccountClosure(array $lifeCycleBehavior, array $currentUserLifeCycle): bool
    {
        $enableAccountClosure = ($lifeCycleBehavior[0]['fdtaskslifecycleenableaccountclosure'][0] ?? 'FALSE') === 'TRUE';
        return $enableAccountClosure;
    }

    protected function processAccountClosure(array $lifeCycleBehavior, string $userDN, array $currentUserLifeCycle)
    {
        $pattern = '/\{([A-Za-z0-9\-\:\.\_]+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';
        $userStateHistory = $currentUserLifeCycle[0]['supannressourceetatdate'] ?? [];
        $this->gateway->unsetCountKeys($userStateHistory);

        $taskPreResourceRaw = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0] ?? '';
        $taskPreState = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0] ?? '';
        $taskPreSubState = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? '';
        $regexPattern = $lifeCycleBehavior[0]['fdtaskslifecycleregexpattern'][0] ?? null;
        $preResourceIsRegex = ($taskPreResourceRaw === 'REGEX');

        $matchingResources = [];
        $hasActiveResource = false;

        foreach ($userStateHistory as $resourceString) {
            preg_match($pattern, $resourceString, $matches);
            $resourceName = $matches[1] ?? '';
            $resourceState = $matches[2] ?? '';

            if (empty($resourceName) || empty($resourceState)) {
                continue;
            }

            $isMatched = false;
            if ($preResourceIsRegex) {
                $currentPattern = $regexPattern;
                if ($currentPattern === '*') {
                    $currentPattern = '.*';
                }
                if ($currentPattern && @preg_match('/' . $currentPattern . '/', $resourceName)) {
                    $isMatched = true;
                }
            } else {
                if ($resourceName === $taskPreResourceRaw) {
                    $isMatched = true;
                }
            }

            if ($isMatched) {
                $matchingResources[] = [
                    'name' => $resourceName,
                    'state' => $resourceState,
                ];
                if ($resourceState === 'A' && $resourceName !== 'COMPTE') {
                    $hasActiveResource = true;
                }
            }
        }

        if (!empty($matchingResources) && !$hasActiveResource) {
            $accountResourceFound = false;
            $updatedStateHistory = $userStateHistory;

            for ($i = 0; $i < count($userStateHistory); $i++) {
                $resourceString = $userStateHistory[$i];
                preg_match($pattern, $resourceString, $matches);
                $resourceName = $matches[1] ?? '';

                if ($resourceName === 'COMPTE') {
                    $todayDate = date('Ymd');
                    $updatedStateHistory[$i] = "{COMPTE}S:SupannVerrouAdministratif:" . $todayDate . ":";
                    $accountResourceFound = true;
                    break;
                }
            }

            if (!$accountResourceFound) {
                return 'No ACCOUNT resource found to deactivate';
            }

            $ldapEntry = ['supannRessourceEtatDate' => $updatedStateHistory];

            try {
                $opResult = ldap_modify($this->gateway->ds, $userDN, $ldapEntry);
                return $opResult ? 'ACCOUNT_CLOSURE_APPLIED' : 'LDAP modification failed';
            } catch (\Exception $e) {
                return 'Ldap Error: ' . $e->getMessage();
            }
        } elseif (empty($matchingResources)) {
            return 'NO_MATCHING_RESOURCES';
        } else {
            return 'NO_CLOSURE_NEEDED';
        }
    }

    public function processLifeCycleTasks(array $listTasks): array
    {
        $result = [];
        $webservice = new \FusionDirectory\Rest\WebServiceCall(
            $_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/login',
            'POST'
        );
        $webservice->setCurlSettings();

        foreach ($listTasks as $task) {
            if ($this->gateway->statusAndScheduleCheck($task)) {
                $mainTaskDn = $task['fdtasksgranularmaster'][0];
                $lifeCycleBehavior = $this->getLifeCycleBehaviorFromMainTask($mainTaskDn);

                $repeatableSchedule = null;
                $repeatableFlag = $lifeCycleBehavior[0]['fdtasksrepeatable'][0] ?? null;
                if ($repeatableFlag !== null && strcasecmp($repeatableFlag, 'TRUE') === 0) {
                    $repeatableSchedule = $lifeCycleBehavior[0]['fdtasksrepeatableschedule'][0] ?? null;
                }

                $currentUserLifeCycle = $this->coreUtils->getUserSupannAccountStatus(
                    $task['fdtasksgranulardn'][0],
                    $this->gateway
                );

                $isAccountClosureEnabled = $this->shouldProcessAccountClosure($lifeCycleBehavior, $currentUserLifeCycle);

                if ($isAccountClosureEnabled) {
                    $lifeCycleResult = $this->processAccountClosure(
                        $lifeCycleBehavior,
                        $task['fdtasksgranulardn'][0],
                        $currentUserLifeCycle
                    );

                    if ($lifeCycleResult === 'ACCOUNT_CLOSURE_APPLIED') {
                        $result[$task['dn']]['results'] = json_encode('Account closure processed successfully for ' . $task['fdtasksgranulardn'][0]);
                        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
                        $result[$task['dn']]['refreshUser'] = $webservice->refreshUserInfo($task['fdtasksgranulardn'][0]);
                    } elseif ($lifeCycleResult === 'NO_MATCHING_RESOURCES' || $lifeCycleResult === 'NO_CLOSURE_NEEDED') {
                        $result[$task['dn']]['results'] = json_encode($lifeCycleResult . ' for ' . $task['fdtasksgranulardn'][0]);
                        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
                    } else {
                        $result[$task['dn']]['results'] = json_encode('Error: ' . $lifeCycleResult);
                        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $lifeCycleResult, $mainTaskDn, $repeatableSchedule);
                    }
                } else {
                    if ($this->isLifeCycleRequiringModification($lifeCycleBehavior, $currentUserLifeCycle)) {
                        $lifeCycleResult = $this->updateLifeCycle($lifeCycleBehavior, $task['fdtasksgranulardn'][0], $currentUserLifeCycle);

                        if ($lifeCycleResult === true) {
                            $result[$task['dn']]['results'] = json_encode('Account states modified for ' . $task['fdtasksgranulardn'][0]);
                            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
                            $result[$task['dn']]['refreshUser'] = $webservice->refreshUserInfo($task['fdtasksgranulardn'][0]);
                        } else {
                            $result[$task['dn']]['results'] = json_encode('Error: ' . $lifeCycleResult);
                            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $lifeCycleResult, $mainTaskDn, $repeatableSchedule);
                        }
                    } else {
                        $result[$task['dn']]['results'] = 'Sub-task updated for: ' . $task['fdtasksgranulardn'][0];
                        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '3', $mainTaskDn, $repeatableSchedule);
                    }
                }
            }
        }

        if (empty($result)) {
            $result = 'No tasks of type "Life Cycle" requires processing.';
        }

        return [$result];
    }

    protected function isLifeCycleRequiringModification(array $lifeCycleBehavior, array $currentUserLifeCycle): bool
    {
        $pattern = '/\{([A-Za-z0-9\-\:\.\_]+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';

        if (empty($currentUserLifeCycle[0]['supannressourceetatdate'][0])) {
            return false;
        }

        $taskPreResource = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0] ?? '';
        $taskPreState = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0] ?? '';
        $taskPreSubState = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? '';
        $regexPattern = $lifeCycleBehavior[0]['fdtaskslifecycleregexpattern'][0] ?? null;

        if (empty($taskPreResource) || empty($taskPreState)) {
            return false;
        }

        $preResourceIsRegex = ($taskPreResource === 'REGEX');

        foreach ($currentUserLifeCycle[0]['supannressourceetatdate'] as $resourceString) {
            preg_match($pattern, $resourceString, $matches);

            $userResourceName = $matches[1] ?? '';
            $userCurrentState = $matches[2] ?? '';
            $userCurrentSubState = $matches[3] ?? '';
            $userEndDateStr = $matches[5] ?? '';

            if (empty($userEndDateStr)) {
                continue;
            }
            $userEndDateTimestamp = strtotime($userEndDateStr);
            if ($userEndDateTimestamp === false || $userEndDateTimestamp > time()) {
                continue;
            }

            $nameMatch = false;
            if ($preResourceIsRegex) {
                if ($regexPattern && !empty($userResourceName) && @preg_match('/' . $regexPattern . '/', $userResourceName)) {
                    $nameMatch = true;
                }
            } else {
                if ($userResourceName === $taskPreResource) {
                    $nameMatch = true;
                }
            }

            if ($nameMatch && $userCurrentState === $taskPreState && (empty($taskPreSubState) || $userCurrentSubState === $taskPreSubState)) {
                return true;
            }
        }

        return false;
    }

    protected function updateLifeCycle(array $lifeCycleBehavior, string $userDN, array $currentUserLifeCycle)
    {
        $pattern = '/\{([A-Za-z0-9\-\:\.\_]+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';
        $userStateHistory = $currentUserLifeCycle[0]['supannressourceetatdate'] ?? [];
        $this->gateway->unsetCountKeys($userStateHistory);

        $taskPostResourceRaw = $lifeCycleBehavior[0]['fdtaskslifecyclepostresource'][0] ?? '';
        $taskPostState = $lifeCycleBehavior[0]['fdtaskslifecyclepoststate'][0] ?? '';
        $taskPostSubState = $lifeCycleBehavior[0]['fdtaskslifecyclepostsubstate'][0] ?? '';
        $taskPostExtraDays = (int) ($lifeCycleBehavior[0]['fdtaskslifecyclepostenddate'][0] ?? 0);
        $regexPattern = $lifeCycleBehavior[0]['fdtaskslifecycleregexpattern'][0] ?? null;

        if (empty($taskPostResourceRaw) || empty($taskPostState)) {
            return 'Error: Post-resource or Post-state not defined in task configuration.';
        }

        $postResourceIsRegex = ($taskPostResourceRaw === 'REGEX');
        $updatedStateHistory = $userStateHistory;
        $modificationsMadeCount = 0;
        $foundPostTarget = false;

        for ($i = 0; $i < count($userStateHistory); $i++) {
            $currentUserResourceString = $userStateHistory[$i];
            preg_match($pattern, $currentUserResourceString, $matches);

            $userOriginalResourceName = $matches[1] ?? '';
            $userOriginalRawState = $matches[2] ?? '';
            $userOriginalRawSubState = $matches[3] ?? '';
            $userOriginalPeriodEndDateStr = $matches[5] ?? '';

            if ($postResourceIsRegex) {
                if ($regexPattern && !empty($userOriginalResourceName) && @preg_match('/' . $regexPattern . '/', $userOriginalResourceName)) {
                    $foundPostTarget = true;
                }
            } else {
                if ($userOriginalResourceName === $taskPostResourceRaw) {
                    $foundPostTarget = true;
                }
            }

            $targetThisResourceForUpdate = false;

            if ($postResourceIsRegex) {
                if ($regexPattern && !empty($userOriginalResourceName) && @preg_match('/' . $regexPattern . '/', $userOriginalResourceName)) {
                    $targetThisResourceForUpdate = true;
                }
            } else {
                if ($userOriginalResourceName === $taskPostResourceRaw) {
                    $targetThisResourceForUpdate = true;
                }
            }

            if ($targetThisResourceForUpdate) {
                if (empty($userOriginalPeriodEndDateStr) || !\DateTime::createFromFormat('Ymd', $userOriginalPeriodEndDateStr)) {
                    continue;
                }

                $newPeriodStartDateStr = $userOriginalPeriodEndDateStr;
                $newPeriodEndDateObject = \DateTime::createFromFormat('Ymd', $newPeriodStartDateStr);
                $newPeriodEndDateObject->modify('+' . $taskPostExtraDays . ' days');
                $newPeriodEndDateFormatted = $newPeriodEndDateObject->format('Ymd');

                $newResourceStringCore = '{' . $userOriginalResourceName . '}' . $taskPostState;
                if (!empty($taskPostSubState)) {
                    $newResourceStringCore .= ':' . $taskPostSubState;
                } else {
                    $newResourceStringCore .= ':';
                }

                $updatedStateHistory[$i] = $newResourceStringCore . ':' . $newPeriodStartDateStr . ':' . $newPeriodEndDateFormatted;
                $modificationsMadeCount++;
            }
        }

        if ($modificationsMadeCount === 0) {
            if ($foundPostTarget === false) {
                $targetDesc = $postResourceIsRegex ? ("pattern '" . ($regexPattern ?? '') . "'") : ("resource '" . $taskPostResourceRaw . "'");
                return 'Post-state target ' . $targetDesc . ' not found on user profile';
            }
            return true;
        }

        $ldapEntry = ['supannRessourceEtatDate' => $updatedStateHistory];

        try {
            $opResult = ldap_modify($this->gateway->ds, $userDN, $ldapEntry);
            return $opResult;
        } catch (\Exception $e) {
            return 'Ldap Error: ' . $e->getMessage();
        }
    }
}
