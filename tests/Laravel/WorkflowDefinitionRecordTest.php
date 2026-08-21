<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Laravel\Models\WorkflowDefinitionRecord;
use Webong\WorkFlow\Laravel\Models\WorkflowStepDefinitionRecord;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

final class WorkflowDefinitionRecordTest extends TestCase
{
    private static ?Dispatcher $dispatcher = null;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'testing');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getDatabaseManager()->setDefaultConnection('testing');
        if (self::$dispatcher === null) {
            $container = new Container();
            self::$dispatcher = new Dispatcher($container);
            $container->instance('events', self::$dispatcher);
            Container::setInstance($container);
            Facade::setFacadeApplication($container);
        }
        Model::setEventDispatcher(self::$dispatcher);
        $this->connection = $capsule->getConnection('testing');

        $this->connection->getSchemaBuilder()->create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 150);
            $table->unsignedInteger('version')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        $this->connection->getSchemaBuilder()->create('workflow_definition_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_definition_id');
            $table->string('step_id', 150);
            $table->string('label', 255);
            $table->boolean('critical')->default(true);
            $table->boolean('retriable')->default(false);
            $table->json('depends_on')->nullable();
            $table->json('metadata')->nullable();
            $table->json('retry_policy')->nullable();
            $table->unsignedInteger('order_column')->default(1);
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'step_id']);
            $table->index(['workflow_definition_id', 'order_column']);
        });
    }

    public function test_steps_are_sorted_within_their_definition_and_map_to_the_domain_definition(): void
    {
        $definition = WorkflowDefinitionRecord::query()->create([
            'key' => 'onboarding',
            'version' => 2,
            'metadata' => ['surface' => 'mobile'],
        ]);

        $definition->steps()->create([
            'step_id' => 'connect',
            'label' => 'Connect',
        ]);
        $definition->steps()->create([
            'step_id' => 'verify',
            'label' => 'Verify',
            'depends_on' => ['connect'],
            'retry_policy' => [
                'enabled' => true,
                'max_attempts' => 4,
                'backoff_seconds' => 30,
                'idempotent' => true,
            ],
        ]);
        $definition->load('steps');

        $flow = $definition->toFlowDefinition();

        self::assertSame('onboarding', $flow->key);
        self::assertSame(2, $flow->version);
        self::assertSame(['connect', 'verify'], array_map(
            static fn (FlowStepDefinition $step): string => $step->id,
            $flow->steps,
        ));
        self::assertSame(['connect'], $flow->step('verify')?->dependsOn);
        self::assertSame(4, $flow->step('verify')?->retryPolicy?->maxAttempts);
    }

    public function test_sorting_operations_do_not_cross_definition_boundaries(): void
    {
        $firstDefinition = WorkflowDefinitionRecord::query()->create(['key' => 'first']);
        $secondDefinition = WorkflowDefinitionRecord::query()->create(['key' => 'second']);

        $firstStepOne = $firstDefinition->steps()->create(['step_id' => 'one', 'label' => 'One']);
        $firstStepTwo = $firstDefinition->steps()->create(['step_id' => 'two', 'label' => 'Two']);
        $secondDefinition->steps()->create(['step_id' => 'other', 'label' => 'Other']);

        self::assertSame(2, $firstStepTwo->order_column);
        $firstStepTwo->moveToStart();

        self::assertSame(
            ['two', 'one'],
            $firstDefinition->steps()->ordered()->pluck('step_id')->all(),
        );
        self::assertSame(
            ['other'],
            $secondDefinition->steps()->ordered()->pluck('step_id')->all(),
        );

        WorkflowStepDefinitionRecord::setNewOrderForDefinition(
            $firstDefinition->getKey(),
            [$firstStepOne->getKey(), $firstStepTwo->getKey()],
        );

        self::assertSame(
            ['one', 'two'],
            $firstDefinition->steps()->ordered()->pluck('step_id')->all(),
        );
        self::assertSame(
            ['other'],
            $secondDefinition->steps()->ordered()->pluck('step_id')->all(),
        );
    }

    public function test_a_step_cannot_be_moved_before_a_dependency(): void
    {
        $definition = WorkflowDefinitionRecord::query()->create(['key' => 'ordered']);
        $dependency = $definition->steps()->create(['step_id' => 'connect', 'label' => 'Connect']);
        $dependent = $definition->steps()->create([
            'step_id' => 'verify',
            'label' => 'Verify',
            'depends_on' => ['connect'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow step 'verify' must be ordered after its dependency 'connect'.");

        $dependent->moveToStart();
    }

    public function test_bulk_reordering_rejects_an_order_that_breaks_dependencies(): void
    {
        $definition = WorkflowDefinitionRecord::query()->create(['key' => 'bulk-ordered']);
        $dependency = $definition->steps()->create(['step_id' => 'connect', 'label' => 'Connect']);
        $dependent = $definition->steps()->create([
            'step_id' => 'verify',
            'label' => 'Verify',
            'depends_on' => ['connect'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow step 'verify' must be ordered after its dependency 'connect'.");

        WorkflowStepDefinitionRecord::setNewOrderForDefinition(
            $definition->getKey(),
            [$dependent->getKey(), $dependency->getKey()],
        );
    }

    public function test_materializing_a_directly_corrupted_order_throws_validation(): void
    {
        $definition = WorkflowDefinitionRecord::query()->create(['key' => 'corruptible']);
        $definition->steps()->create(['step_id' => 'connect', 'label' => 'Connect']);
        $dependent = $definition->steps()->create([
            'step_id' => 'verify',
            'label' => 'Verify',
            'depends_on' => ['connect'],
        ]);
        $dependent->order_column = 0;
        $dependent->saveQuietly();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow step 'verify' must be ordered after its dependency 'connect'.");

        $definition->toFlowDefinition();
    }
}
