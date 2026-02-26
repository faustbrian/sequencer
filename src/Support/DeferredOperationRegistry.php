<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Cline\Sequencer\Contracts\DeferredOperation;
use Cline\Sequencer\Exceptions\DeferredOperationNotFoundException;
use Cline\Sequencer\Exceptions\InvalidDeferredConfigurationException;

use function array_flip;
use function array_keys;
use function class_exists;
use function config;
use function in_array;
use function is_array;
use function is_string;
use function is_subclass_of;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class DeferredOperationRegistry
{
    /**
     * Resolve the operation identifier that should be persisted.
     */
    public function normalizeForStorage(string $identifier): string
    {
        [$map, $enforced] = $this->loadMap();

        if (in_array($identifier, array_keys($map), true)) {
            return $identifier;
        }

        $reversed = array_flip($map);

        if (in_array($identifier, array_keys($reversed), true)) {
            return $reversed[$identifier];
        }

        if ($enforced) {
            throw DeferredOperationNotFoundException::forIdentifier($identifier);
        }

        if ($this->isDeferredOperationClass($identifier)) {
            return $identifier;
        }

        throw DeferredOperationNotFoundException::forIdentifier($identifier);
    }

    /**
     * Resolve a persisted operation identifier to a concrete class name.
     *
     * @return class-string<DeferredOperation>
     */
    public function resolveClass(string $identifier): string
    {
        [$map, $enforced] = $this->loadMap();

        if (in_array($identifier, array_keys($map), true)) {
            return $map[$identifier];
        }

        if ($this->isDeferredOperationClass($identifier) && !$enforced) {
            return $identifier;
        }

        throw DeferredOperationNotFoundException::forIdentifier($identifier);
    }

    /**
     * @return array{array<string, class-string<DeferredOperation>>, bool}
     */
    private function loadMap(): array
    {
        /** @var mixed $taskMapConfig */
        $taskMapConfig = config('sequencer.deferred.taskMap', []);

        /** @var mixed $enforcedMapConfig */
        $enforcedMapConfig = config('sequencer.deferred.enforceTaskMap', []);

        $taskMap = is_array($taskMapConfig) ? $taskMapConfig : [];
        $enforcedMap = is_array($enforcedMapConfig) ? $enforcedMapConfig : [];

        $hasTaskMap = $taskMap !== [];
        $hasEnforcedMap = $enforcedMap !== [];

        if ($hasTaskMap && $hasEnforcedMap) {
            throw InvalidDeferredConfigurationException::conflictingTaskMaps();
        }

        $activeMap = $hasEnforcedMap ? $enforcedMap : $taskMap;

        /** @var array<string, class-string<DeferredOperation>> $normalized */
        $normalized = [];

        foreach ($activeMap as $alias => $class) {
            if (!is_string($alias) || !$this->isDeferredOperationClass($class)) {
                throw InvalidDeferredConfigurationException::invalidTaskClass((string) $alias, (string) $class);
            }

            $normalized[$alias] = $class;
        }

        return [$normalized, $hasEnforcedMap];
    }

    private function isDeferredOperationClass(mixed $class): bool
    {
        return is_string($class)
            && class_exists($class)
            && is_subclass_of($class, DeferredOperation::class);
    }
}
