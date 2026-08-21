<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowStateSerializer;
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;

final class WorkFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/work-flow.php', 'work-flow');

        $this->app->singleton(FlowStateSerializer::class, DefaultFlowStateSerializer::class);
        $this->app->singleton(FlowStateStoreFactory::class, function (): LaravelFlowStateStoreFactory {
            $configuration = (array) $this->app->make(ConfigRepository::class)->get('work-flow');
            $driver = is_string($configuration['driver'] ?? null) ? $configuration['driver'] : 'database';

            $connection = null;
            $cache = null;
            $locks = null;

            if ($driver === 'database') {
                $database = $this->app->make(DatabaseManager::class);
                $databaseConfiguration = is_array($configuration['database'] ?? null) ? $configuration['database'] : [];
                $connectionName = is_string($databaseConfiguration['connection'] ?? null)
                    ? $databaseConfiguration['connection']
                    : null;
                $connection = $database->connection($connectionName);
            }

            if ($driver === 'redis') {
                $redis = is_array($configuration['redis'] ?? null) ? $configuration['redis'] : [];
                $cacheManager = $this->app->make(CacheManager::class);
                $storeName = is_string($redis['store'] ?? null) ? $redis['store'] : null;
                $cache = $cacheManager->store($storeName);
                $store = $cache->getStore();

                if (! $store instanceof LockProvider) {
                    throw new InvalidArgumentException('The configured WorkFlow cache store does not support atomic locks.');
                }

                $locks = $store;
            }

            return new LaravelFlowStateStoreFactory(
                serializer: $this->app->make(FlowStateSerializer::class),
                driver: $driver,
                configuration: $configuration,
                connection: $connection,
                cache: $cache,
                locks: $locks,
            );
        });
    }

    public function boot(): void
    {
        $configPath = $this->app->make('path.config');
        $databasePath = $this->app->make('path.database');

        if (! is_string($configPath) || ! is_string($databasePath)) {
            throw new InvalidArgumentException('The Laravel application does not expose config and database paths.');
        }

        $this->publishes([
            __DIR__ . '/../../config/work-flow.php' => $configPath . '/work-flow.php',
        ], 'work-flow-config');

        $this->publishes([
            __DIR__ . '/../../database/migrations/2026_08_20_000000_create_workflow_states_table.php' => $databasePath . '/migrations/2026_08_20_000000_create_workflow_states_table.php',
            __DIR__ . '/../../database/migrations/2026_08_21_000001_create_workflow_definitions_table.php' => $databasePath . '/migrations/2026_08_21_000001_create_workflow_definitions_table.php',
            __DIR__ . '/../../database/migrations/2026_08_21_000002_create_workflow_definition_steps_table.php' => $databasePath . '/migrations/2026_08_21_000002_create_workflow_definition_steps_table.php',
        ], 'work-flow-migrations');
    }
}
