<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowStateMigrator;
use Webong\WorkFlow\ValueObjects\FlowState;

final class FlowStateMigrationRunner
{
    /** @param iterable<FlowStateMigrator> $migrators */
    public function migrate(FlowState $state, int $targetVersion, iterable $migrators): FlowState
    {
        if ($targetVersion < $state->version) {
            throw new InvalidArgumentException('Flow state downgrades are not supported.');
        }

        if ($targetVersion === $state->version) {
            return $state;
        }

        $available = [];
        foreach ($migrators as $migrator) {
            $fromVersion = $migrator->fromVersion();
            $toVersion = $migrator->toVersion();

            if ($fromVersion < 1 || $toVersion !== $fromVersion + 1) {
                throw new InvalidArgumentException('Flow state migrators must advance one valid version at a time.');
            }

            if (isset($available[$fromVersion])) {
                throw new InvalidArgumentException("Multiple flow state migrators exist for version {$fromVersion}.");
            }

            $available[$fromVersion] = $migrator;
        }

        while ($state->version < $targetVersion) {
            $migrator = $available[$state->version] ?? null;

            if (! $migrator instanceof FlowStateMigrator) {
                throw new InvalidArgumentException("No flow state migrator exists for version {$state->version}.");
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
