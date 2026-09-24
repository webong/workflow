<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use Webong\WorkFlow\Contracts\ForgettableFlowStateStore;
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

trait RunStoreAssertions
{
    private function assertRunIsolation(ForgettableFlowStateStore $storage): void
    {
        $definition = new FlowDefinition('run-isolation', [new FlowStepDefinition('authorize', 'Authorize')], metadata: ['z' => 'last', 'a' => 'first', 'ratio' => 1.0], version: 7);
        $first = new RunScopedFlowStateStore($storage, 'first');
        $second = new RunScopedFlowStateStore($storage, str_repeat('second', 100));
        $first->put($definition->key, (new FlowRun('first', $definition))->initialState());
        $second->put($definition->key, (new FlowRun($second->runId, $definition))->initialState());
        $first->mutate($definition->key, static fn (?FlowState $state): FlowState => $state->withMetadata(['one' => true]));

        self::assertTrue($first->get($definition->key)->metadata['one']);
        self::assertArrayNotHasKey('one', $second->get($definition->key)->metadata);
        self::assertSame(7, $first->get($definition->key)->run->definition->version);
        $first->get($definition->key)->run->assertDefinition($definition);

        $first->forget($definition->key);
        self::assertNull($first->get($definition->key));
        self::assertNotNull($second->get($definition->key));
        $second->forget($definition->key);
    }
}
