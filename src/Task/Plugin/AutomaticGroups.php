<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Ldap\Backend;
use Orchestrator\Task\TaskGateway;

class AutomaticGroups implements EndpointInterface
{
    private Backend $fdConfiguration;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->fdConfiguration = new Backend();
    }

    public function processEndPointGet(): array
    {
        return $this->gateway->getObjectTypeTask('automaticGroups');
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
        if (isset($data['type']) && $data['type'] === 'dynamic-group') {
            return $this->processDynamicGroupCreation($this->gateway->getObjectTypeTask('automaticGroupsDynamic'));
        }

        return $this->processAutomaticGroups($this->gateway->getObjectTypeTask('automaticGroups'));
    }

    public function processAutomaticGroups(array $automaticGroupsTasks): array
    {
        $result = [];

        if (empty($automaticGroupsTasks)) {
            return ['No automatic groups tasks require processing.'];
        }

        foreach ($automaticGroupsTasks as $task) {
            try {
                $mainTaskDn = null;
                $repeatableSchedule = null;

                if (!$this->gateway->statusAndScheduleCheck($task)) {
                    continue;
                }

                $userDn = $task['fdtasksgranulardn'][0] ?? null;
                if (empty($userDn)) {
                    throw new \RuntimeException('Missing user DN in task');
                }

                $mainTaskDn = $task['fdtasksgranularmaster'][0];
                $mainTaskConfig = $this->getAutomaticGroupsMainTask($mainTaskDn);

                $rawRepeatable = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? '';
                $isTaskRepeatable = (strcasecmp($rawRepeatable, 'TRUE') === 0);
                $repeatableSchedule = $isTaskRepeatable ? ($mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? null) : null;

                $targetGroup = $mainTaskConfig[0]['fdtasksautomaticgroupsofname'][0] ?? null;
                $resource = $mainTaskConfig[0]['fdtasksautomaticgroupspreresource'][0] ?? null;
                $state = $mainTaskConfig[0]['fdtasksautomaticgroupsprestate'][0] ?? null;
                $subState = $mainTaskConfig[0]['fdtasksautomaticgroupspresubstate'][0] ?? null;
                $pattern = $mainTaskConfig[0]['fdtasksautomaticgroupsregexpattern'][0] ?? null;

                if (empty($targetGroup)) {
                    throw new \RuntimeException('Missing target group in task configuration');
                }

                $shouldAddToGroup = false;

                if ($resource !== 'NONE' && !empty($resource) && !empty($state)) {
                    if (isset($pattern)) {
                        $supannResources = $this->gateway->getLdapTasks(
                            '(objectClass=fdSupannRessource)',
                            ['fdSupannRessourceName'],
                            '',
                            $_ENV['LDAP_BASE']
                        );
                        unset($supannResources['count']);

                        foreach ($supannResources as $supannRessource) {
                            if (@preg_match('/' . $pattern . '/', $supannRessource['fdsupannressourcename'][0])) {
                                $resourceReplace = str_replace('REGEX', $supannRessource['fdsupannressourcename'][0], $resource);
                                $userSupannState = $this->getUserSupannState($userDn);
                                $shouldAddToGroup = $this->checkUserSupannState($userSupannState, $resourceReplace, $state, $subState);
                                if ($shouldAddToGroup) {
                                    break;
                                }
                            }
                        }
                    } else {
                        $userSupannState = $this->getUserSupannState($userDn);
                        $shouldAddToGroup = $this->checkUserSupannState($userSupannState, $resource, $state, $subState);
                    }

                    $resultMessage = $this->manageGroup($shouldAddToGroup, $userDn, $targetGroup);
                    $result[$task['dn']]['result'] = implode(PHP_EOL, $resultMessage);
                    $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
                }
            } catch (\Exception $e) {
                $result[$task['dn']]['result'] = 'Error: ' . $e->getMessage();
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn, $repeatableSchedule);
            }
        }

        return $result;
    }

    private function manageGroup(bool $shouldAddToGroup, string $userDn, string $targetGroup): array
    {
        $resultMessage = [];
        $members = $this->getGroupMembers($targetGroup);

        if ($shouldAddToGroup) {
            if (in_array($userDn, $members, true)) {
                $resultMessage[] = "User $userDn already in group $targetGroup";
            } else {
                $this->addUserToGroup($userDn, $targetGroup);
                $resultMessage[] = "User $userDn added to group $targetGroup";
            }
        } else {
            if (!in_array($userDn, $members)) {
                $resultMessage[] = "User $userDn not in group $targetGroup";
            } else {
                $this->removeUserFromGroup($userDn, $targetGroup);
                $resultMessage[] = "User $userDn removed from group $targetGroup";
            }
        }

        return $resultMessage;
    }

    public function processDynamicGroupCreation(array $dynamicGroupTasks): array
    {
        $result = [];

        if (empty($dynamicGroupTasks)) {
            return ['No dynamic group tasks require processing.'];
        }

        foreach ($dynamicGroupTasks as $task) {
            try {
                $mainTaskDn = null;
                $repeatableSchedule = null;

                if (!$this->gateway->statusAndScheduleCheck($task)) {
                    continue;
                }

                $mainTaskDn = $task['fdtasksgranularmaster'][0];
                $mainTaskConfig = $this->getAutomaticGroupsMainTask($mainTaskDn);

                $rawRepeatable = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? '';
                $isTaskRepeatable = (strcasecmp($rawRepeatable, 'TRUE') === 0);
                $repeatableSchedule = $isTaskRepeatable ? ($mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? null) : null;

                $dynamicURL = $mainTaskConfig[0]['fdtasksautomaticgroupsdynamicurl'][0] ?? null;
                $dynamicName = $mainTaskConfig[0]['fdtasksautomaticgroupsdynamicname'][0] ?? null;
                $pattern = $mainTaskConfig[0]['fdtasksautomaticgroupsregexpattern'][0] ?? null;
                $resultMessage = [];

                if (isset($pattern)) {
                    $supannResources = $this->gateway->getLdapTasks(
                        '(objectClass=fdSupannRessource)',
                        ['fdSupannRessourceName'],
                        '',
                        $_ENV['LDAP_BASE']
                    );
                    unset($supannResources['count']);

                    foreach ($supannResources as $supannRessource) {
                        if (@preg_match('/' . $pattern . '/', $supannRessource['fdsupannressourcename'][0])) {
                            $dynamicNameReplace = str_replace('regex', strtolower($supannRessource['fdsupannressourcename'][0]), $dynamicName);
                            $dynamicURLReplace = str_replace('REGEX', $supannRessource['fdsupannressourcename'][0], $dynamicURL);
                            $this->createDynamicGroup($dynamicNameReplace, $dynamicURLReplace);
                            $resultMessage[] = "Created dynamic group '$dynamicNameReplace'";
                        }
                    }
                } else {
                    $this->createDynamicGroup($dynamicName, $dynamicURL);
                    $resultMessage[] = "Created dynamic group '$dynamicName'";
                }

                $result[$task['dn']]['result'] = implode(PHP_EOL, $resultMessage);
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
            } catch (\Exception $e) {
                $result[$task['dn']]['result'] = 'Error: ' . $e->getMessage();
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn, $repeatableSchedule);
            }
        }

        return $result;
    }

    private function getAutomaticGroupsMainTask(string $mainTaskDn): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdTasksAutomaticGroups)',
            [
                'fdTasksAutomaticGroupsOfName', 'fdTasksAutomaticGroupsPreResource',
                'fdTasksAutomaticGroupsPreState', 'fdTasksAutomaticGroupsPreSubState',
                'fdTasksAutomaticGroupsDynamicGroup', 'fdTasksAutomaticGroupsDynamicURL',
                'fdTasksAutomaticGroupsDynamicName', 'fdtasksautomaticgroupsregexpattern',
                'fdTasksRepeatableSchedule', 'fdTasksRepeatable',
            ],
            '',
            $mainTaskDn
        );
    }

    private function getUserSupannState(string $userDn): array
    {
        $result = $this->gateway->getLdapTasks(
            '(objectClass=supannPerson)',
            ['supannRessourceEtat'],
            '',
            $userDn
        );
        $this->gateway->unsetCountKeys($result);
        return $result;
    }

    private function checkUserSupannState(array $userSupannState, string $resource, string $state, ?string $subState): bool
    {
        if (empty($userSupannState[0]['supannressourceetat'])) {
            return false;
        }

        foreach ($userSupannState[0]['supannressourceetat'] as $value) {
            $expectedState = '{' . $resource . '}' . $state;
            if (!empty($subState)) {
                $expectedState .= ':' . $subState;
            }
            if ($value === $expectedState) {
                return true;
            }
        }

        return false;
    }

    private function getGroupMembers(string $groupDn): array
    {
        $groupInfo = $this->gateway->getLdapTasks(
            '(objectClass=groupOfNames)',
            ['member'],
            '',
            $groupDn
        );
        $this->gateway->unsetCountKeys($groupInfo);
        return $groupInfo[0]['member'] ?? [];
    }

    private function addUserToGroup(string $userDn, string $groupDn): bool
    {
        $members = $this->getGroupMembers($groupDn);
        if (in_array($userDn, $members)) {
            return true;
        }
        $members[] = $userDn;
        return $this->updateLdap($groupDn, ['member' => $members], 'add', $userDn);
    }

    private function removeUserFromGroup(string $userDn, string $groupDn): bool
    {
        $members = $this->getGroupMembers($groupDn);
        if (!in_array($userDn, $members)) {
            return true;
        }
        return $this->updateLdap($groupDn, ['member' => [$userDn]], 'remove', $userDn);
    }

    private function createDynamicGroup(string $groupName, string $ldapUrl): bool
    {
        if (empty($groupName) || empty($ldapUrl)) {
            throw new \RuntimeException('Missing required parameters for dynamic group creation');
        }

        $fdConfigAttributes = $this->fdConfiguration->getFDConfigAttributes();
        $groupBranch = $fdConfigAttributes[0]['fdGroupRDN'][0];
        $baseDN = $_ENV['LDAP_BASE'];
        $groupDN = "cn=$groupName,$groupBranch,$baseDN";

        $existingGroup = $this->gateway->getLdapTasks(
            "(cn=$groupName)",
            ['cn', 'objectClass', 'memberURL'],
            null,
            "$groupBranch,$baseDN"
        );

        if (!empty($existingGroup) && isset($existingGroup[0]['cn'])) {
            return true;
        }

        $entry = [
            'objectClass' => ['groupOfURLs', 'extensibleObject'],
            'cn' => $groupName,
            'memberURL' => $ldapUrl,
            'description' => 'Dynamic group for ' . str_replace('dynamic-', '', $groupName),
        ];

        return $this->updateLdap($groupDN, $entry, 'create');
    }

    private function updateLdap(string $groupDn, array $entry, string $message, string $userDn = ''): bool
    {
        try {
            switch ($message) {
                case 'create':
                    $result = ldap_add($this->gateway->ds, $groupDn, $entry);
                    break;
                case 'remove':
                    $result = ldap_mod_del($this->gateway->ds, $groupDn, $entry);
                    break;
                default:
                    $result = ldap_modify($this->gateway->ds, $groupDn, $entry);
                    break;
            }

            if (!$result) {
                throw new \RuntimeException(ldap_error($this->gateway->ds));
            }

            return true;
        } catch (\Exception $e) {
            throw new \RuntimeException("Error $message group: " . $e->getMessage());
        }
    }
}
