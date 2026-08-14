<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowStateMigrator;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\Services\FlowStateMigrationRunner;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\StepState;

final class FlowStatePersistenceTest extends TestCase
{
    public function test_store_returns_null_for_unknown_flow_and_replaces_existing_state(): void
    {
        $store = new InMemoryFlowStateStore();
        $first = new FlowState(FlowStatus::PENDING);
        $second = new FlowState(FlowStatus::COMPLETED);

        self::assertNull($store->get('setup'));

        $store->put('setup', $first);
        $store->put('setup', $second);

        self::assertSame($second, $store->get('setup'));
    }

    public function test_serializer_preserves_nested_step_state_and_metadata(): void
    {
        $state = new FlowState(
            FlowStatus::RUNNING,
            ['verify' => new StepState(
                FlowStepStatus::PENDING,
                attempts: 2,
                nextRetryAt: 123,
                metadata: ['deferred' => true, 'provider_id' => 'p-1'],
            )],
            currentStep: 'verify',
            metadata: ['request_id' => 'r-1'],
        );

        $roundTrip = (new DefaultFlowStateSerializer())->deserialize(
            (new DefaultFlowStateSerializer())->serialize($state),
        );

        self::assertSame($state->toArray(), $roundTrip->toArray());
    }

    public function test_deserializer_uses_safe_defaults_for_malformed_scalar_fields(): void
    {
        $state = (new DefaultFlowStateSerializer())->deserialize([
            'status' => ['invalid'],
            'steps' => [
                'verify' => [
                    'status' => ['invalid'],
                    'attempts' => 'many',
                ],
            ],
            'failed_steps' => 'verify',
            'status_message' => ['invalid'],
            'version' => '3',
        ]);

        self::assertSame(FlowStatus::PENDING, $state->status);
        self::assertSame(FlowStepStatus::PENDING, $state->steps['verify']->status);
        self::assertSame(0, $state->steps['verify']->attempts);
        self::assertSame([], $state->failedSteps);
        self::assertNull($state->message);
        self::assertSame(1, $state->version);
    }

    public function test_migration_runner_applies_a_chain_of_versioned_migrators(): void
    {
        $state = (new FlowStateMigrationRunner())->migrate(
            new FlowState(FlowStatus::PENDING, version: 1),
            3,
            [
                $this->migrator(1, 2),
                $this->migrator(2, 3),
            ],
        );

        self::assertSame(3, $state->version);
        self::assertSame(['migrated_1_2' => true, 'migrated_2_3' => true], $state->metadata);
    }

    public function test_migration_runner_rejects_downgrades(): void
    {
        $runner = new FlowStateMigrationRunner();

        $this->expectException(InvalidArgumentException::class);
        $runner->migrate(new FlowState(FlowStatus::PENDING, version: 2), 1, []);
    }

    public function test_migration_runner_rejects_a_missing_migrator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FlowStateMigrationRunner())->migrate(
            new FlowState(FlowStatus::PENDING, version: 1),
            2,
            [],
        );
    }

    public function test_migration_runner_rejects_a_gap_in_the_migration_chain(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FlowStateMigrationRunner())->migrate(
            new FlowState(FlowStatus::PENDING, version: 1),
            3,
            [$this->migrator(1, 3)],
        );
    }

    public function test_evaluation_preserves_a_migrated_state_version(): void
    {
        $state = (new FlowEvaluator())->evaluate(
            new FlowDefinition('setup'),
            new FlowState(FlowStatus::PENDING, version: 3),
        );

        self::assertSame(3, $state->version);
    }

    public function test_migration_runner_rejects_duplicate_source_versions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FlowStateMigrationRunner())->migrate(
            new FlowState(FlowStatus::PENDING, version: 1),
            2,
            [
                $this->migrator(1, 2),
                $this->migrator(1, 2),
            ],
        );
    }

    public function test_migration_runner_rejects_an_incorrect_returned_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FlowStateMigrationRunner())->migrate(
            new FlowState(FlowStatus::PENDING, version: 1),
            2,
            [$this->migrator(1, 2, returnedVersion: 3)],
        );
    }

    private function migrator(int $from, int $to, ?int $returnedVersion = null): FlowStateMigrator
    {
        return new class($from, $to, $returnedVersion) implements FlowStateMigrator {
            public function __construct(
                private readonly int $from,
                private readonly int $to,
                private readonly ?int $returnedVersion,
            ) {
            }

            public function fromVersion(): int
            {
                return $this->from;
            }

            public function toVersion(): int
            {
                return $this->to;
            }

            public function migrate(FlowState $state): FlowState
            {
                return new FlowState(
                    $state->status,
                    $state->steps,
                    $state->currentStep,
                    $state->failedSteps,
                    $state->canRetryStep,
                    $state->message,
                    [...$state->metadata, "migrated_{$this->from}_{$this->to}" => true],
                    $this->returnedVersion ?? $this->to,
                );
            }
        };
    }
}
