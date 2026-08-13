<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use InvalidArgumentException;
use Zorvia\WebFlow\Contracts\FlowStateMigrator;
use Zorvia\WebFlow\ValueObjects\FlowState;

final class FlowStateMigrationRunner
{
    /** @param iterable<FlowStateMigrator> $migrators */
    public function migrate(FlowState $state, int $targetVersion, iterable $migrators): FlowState
    {
        if ($targetVersion < $state->version) {
            throw new InvalidArgumentException('Flow state downgrades are not supported.');
        }

        $available = [];
        foreach ($migrators as $migrator) {
            $available[$migrator->fromVersion()] = $migrator;
        }

        while ($state->version < $targetVersion) {
            $migrator = $available[$state->version] ?? null;

            if (! $migrator instanceof FlowStateMigrator) {
                throw new InvalidArgumentException("No flow state migrator exists for version {$state->version}.");
            }

            if ($migrator->toVersion() <= $state->version || $migrator->toVersion() > $targetVersion) {
                throw new InvalidArgumentException('Flow state migrators must advance one valid version at a time.');
            }

            $migrated = $migrator->migrate($state);

            if ($migrated->version !== $migrator->toVersion()) {
                throw new InvalidArgumentException('A flow state migrator must return its declared target version.');
            }

            $state = $migrated;
        }

        return $state;
    }
}
