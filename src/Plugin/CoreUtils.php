<?php

declare(strict_types=1);

namespace Orchestrator\Plugin;

class CoreUtils
{
    public function recursiveArrayFilter(array $array): array
    {
        return array_filter($array, function ($item) {
            if (is_array($item)) {
                $item = $this->recursiveArrayFilter($item);
            }
            return !empty($item);
        });
    }

    public function findMatchingKeys(?array $elements, array $keys): array
    {
        $matching = [];

        if (!empty($elements)) {
            foreach ($elements as $element) {
                foreach ($keys as $key) {
                    if (!empty($element) && array_key_exists($key, $element)) {
                        $matching[] = $key;
                    }
                }
            }
        }

        return $matching;
    }

    public function getArrayValuesRecursive(array $array): array
    {
        return array_reduce($array, function ($carry, $value) {
            return array_merge($carry, is_array($value) ? $this->getArrayValuesRecursive($value) : [$value]);
        }, []);
    }

    public function ensureDirectoryExists(string $path): bool
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $path");
            }
        }
        return true;
    }

    public function getUserSupannAccountStatus(string $userDn, \Orchestrator\Task\TaskGateway $gateway): array
    {
        return $gateway->getLdapTasks(
            '(objectClass=supannPerson)',
            ['supannRessourceEtatDate'],
            '',
            $userDn
        );
    }
}
