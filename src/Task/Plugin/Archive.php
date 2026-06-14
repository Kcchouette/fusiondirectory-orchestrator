<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Task\TaskGateway;

class Archive implements EndpointInterface
{
    private \Orchestrator\Plugin\CoreUtils $coreUtils;

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
        $this->coreUtils = new \Orchestrator\Plugin\CoreUtils();
    }

    public function processEndPointGet(): array
    {
        return $this->gateway->getObjectTypeTask('archive');
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
        $archiveTasks = $this->gateway->getObjectTypeTask('archive');

        $webServiceCall = new \FusionDirectory\Rest\WebServiceCall(
            $_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/login',
            'POST'
        );
        $webServiceCall->setCurlSettings();

        foreach ($archiveTasks as $task) {
            try {
                $mainTaskDn = null;
                $repeatableSchedule = null;

                if (!$this->gateway->statusAndScheduleCheck($task)) {
                    continue;
                }

                $mainTaskDn = $task['fdtasksgranularmaster'][0];
                $mainTaskConfig = $this->getArchiveTaskBehaviorFromMainTask($mainTaskDn);
                $rawRepeatable = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? '';
                $isTaskRepeatable = (strcasecmp($rawRepeatable, 'TRUE') === 0);
                $repeatableSchedule = $isTaskRepeatable ? ($mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? null) : null;
                $desiredSupannStatus = $mainTaskConfig;

                $currentSupannStatus = $this->coreUtils->getUserSupannAccountStatus(
                    $task['fdtasksgranulardn'][0],
                    $this->gateway
                );

                if (!$this->isSupannStatusMatching($desiredSupannStatus, $currentSupannStatus)) {
                    $result[$task['dn']]['result'] = 'User does not meet the criteria for archiving.';
                    $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '3', $mainTaskDn, $repeatableSchedule);
                    continue;
                }

                $archiveUrl = $_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/archive/user/' . rawurlencode($task['fdtasksgranulardn'][0]);
                $webServiceCall->setCurlSettings($archiveUrl, null, 'POST');
                $response = $webServiceCall->execute();

                if ($webServiceCall->getHttpStatusCode() === 204) {
                    $result[$task['dn']]['result'] = 'User ' . $task['fdtasksgranulardn'][0] . ' successfully archived.';
                    $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
                } else {
                    throw new \RuntimeException('Unexpected HTTP status code: ' . $webServiceCall->getHttpStatusCode());
                }
            } catch (\Exception $e) {
                $result[$task['dn']]['result'] = 'Error archiving user: ' . $e->getMessage();
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn, $repeatableSchedule);
            }
        }

        return $result;
    }

    private function getArchiveTaskBehaviorFromMainTask(string $taskDN): array
    {
        return $this->gateway->getLdapTasks(
            '(objectClass=fdArchiveTasks)',
            [
                'fdArchiveTaskResource', 'fdArchiveTaskState', 'fdArchiveTaskSubState',
                'fdTasksRepeatableSchedule', 'fdTasksRepeatable',
            ],
            '',
            $taskDN
        );
    }

    private function isSupannStatusMatching(array $desiredStatus, array $currentStatus): bool
    {
        if (empty($currentStatus[0]['supannressourceetatdate'])) {
            return false;
        }

        $desiredAttributes = [
            'resource' => $desiredStatus[0]['fdarchivetaskresource'][0] ?? null,
            'state' => $desiredStatus[0]['fdarchivetaskstate'][0] ?? null,
            'substate' => $desiredStatus[0]['fdarchivetasksubstate'][0] ?? null,
        ];

        if (!$desiredAttributes['resource'] || !$desiredAttributes['state']) {
            return false;
        }

        foreach ($currentStatus[0]['supannressourceetatdate'] as $key => $resource) {
            if (!is_numeric($key)) {
                continue;
            }

            $parts = explode(':', $resource);
            $resourcePart = str_replace(['{', '}'], '', $parts[0]);
            $substatePart = $parts[1] ?? '';

            $resourceMatch = $resourcePart === $desiredAttributes['resource'] . $desiredAttributes['state'];
            $substateMatch = empty($desiredAttributes['substate']) || $substatePart === $desiredAttributes['substate'];

            if ($resourceMatch && $substateMatch) {
                return true;
            }
        }

        return false;
    }
}
