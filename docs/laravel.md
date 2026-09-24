# Use WorkFlow in Laravel

Laravel support is optional and lives in `ext/Laravel`. It adds state storage,
not a ready-made application workflow or queue job. The framework-neutral
definition, runner, and transition APIs are the same as in the
[PHP guide](php-library.md).

## 1. Install and publish

In your Laravel application:

```sh
composer require webong/workflow
php artisan vendor:publish --tag=work-flow-config
php artisan vendor:publish --tag=work-flow-migrations
php artisan migrate
```

Laravel package discovery registers `WorkFlowServiceProvider`. The published
config is `config/work-flow.php`. Publishing migrations creates the
`workflow_states` polymorphic state table and the optional editable-definition
tables. You do not need `spatie/eloquent-sortable` just to store flow state;
install it only if you use `WorkflowStepDefinitionRecord` and its sortable
step operations.

If your application runs PHP and Composer through Sail or another container,
run these commands through that environment. The package itself requires PHP
8.3 or newer.

## 2. Choose one state store

Database is the default. It uses the app database connection unless you set
`WORK_FLOW_DATABASE_CONNECTION`:

```dotenv
WORK_FLOW_STORE=database
```

For Redis, point `WORK_FLOW_REDIS_STORE` at a **Laravel cache store** backed
by Redis and supporting atomic locks (not merely a Redis connection name):

```dotenv
WORK_FLOW_STORE=redis
WORK_FLOW_REDIS_STORE=redis
WORK_FLOW_REDIS_PREFIX=work-flow
```

The published Redis `ttl` setting defaults to `null`. Keep it that way unless
you intentionally want authoritative flow state to expire. Database and Redis
are state-store choices; neither selects the execution driver.

## 3. Bind state to your model

Use the existing Eloquent model that owns the flow. For example, on your
application's `Order` model:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Webong\WorkFlow\Laravel\Concerns\HasWorkflowStates;

class Order extends Model
{
    use HasWorkflowStates;
}
```

The trait supplies an optional `workflowStates()` relationship. To start a
one-step approval flow, add this service to your application (replace
`App\Models\Order` with your saved Eloquent model):

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

final readonly class OrderApprovalService
{
    public function __construct(private FlowStateStoreFactory $stores)
    {
    }

    public function start(Order $order, string $runId): FlowState
    {
        $definition = new FlowDefinition('order_approval', [
            new FlowStepDefinition('approve', 'Approve the order'),
        ]);
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return $step->id === 'approve';
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return StepResult::deferred('Waiting for approval');
            }
        };

        $store = new RunScopedFlowStateStore($this->stores->for(new FlowStateSubject(
            type: $order->getMorphClass(),
            id: (string) $order->getKey(),
        )), $runId);

        return $store->mutate('order_approval', static fn (?FlowState $current): FlowState => $current
            ?? (new FlowRunner())->run(
                $definition,
                (new FlowRun($runId, $definition))->initialState(),
                new ArrayFlowContext(['order_id' => (string) $order->getKey()]),
                [$executor],
            ));
    }
}
```

Call `start($order, $runId)` from a controller, command, or job after authorizing the
caller. It returns a running state with a pending `approve` step; a second
call with the same run ID returns the same state. This executor only creates
waiting state and does no external work, so the mutation stays short. If you
add network calls, execute them outside the mutation under a host-owned claim.
When your approval event arrives, apply a
`FlowDeferredCompletion` through `FlowStateTransition::complete()` using
the same subject/run-bound store and saved definition, as in the
[executable PHP example](../examples/order-approval.php). For a multi-step
flow, run the runner again after completion so newly eligible steps execute.

`get('order_approval')` reads the current state; `forget('order_approval')`
deletes it. Both database and Redis stores expose the same interface.
Deleting also removes callback deduplication history, so retain old runs for
your provider's retry window and never reuse a deleted run ID.

Use `mutate($flowKey, $transition)` for concurrent updates, especially deferred
callbacks. It locks the database row or uses a Redis lock; keep work inside
that callback short. The host application decides who may access a model's
flow and when to run or resume it.

## 4. Add execution only when needed

The core `InlineFlowExecutionDriver` runs steps in the current PHP process.
`LaravelQueueFlowExecutionDriver` does **not** create a job for you: pass it a
closure that dispatches your own job and returns an execution ID. Your job
resolves the definition, executor, subject, and state store and records the
result. `WORK_FLOW_EXECUTION_DRIVER` in the published config is only a default
value your application can read; the package does not register a queue worker.

For long-running execution, see [the Temporal guide](temporal.md). A Temporal
workflow is not a Redis/database store driver.

## Optional editable definitions

If product users must edit and order steps in the database, install
`spatie/eloquent-sortable` and use `WorkflowDefinitionRecord` with its ordered
`WorkflowStepDefinitionRecord` rows. Call `toFlowDefinition()` to hand the
ordered definition to the framework-neutral runner. For fixed application
flows, construct `FlowDefinition` in code instead; no definition table is
required by the runner.

These models are mutable authoring records. A `FlowRun` pins the complete
definition snapshot at start, so edits and reordering cannot change a saved
run. Publish new versions through your host's publication policy and keep
compatible executor code available for old runs.

## Laravel Boost

This package ships an optional Boost guideline and on-demand skill under
`resources/boost/`. In a Laravel app that does not yet have Boost:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

If Boost was already installed before WorkFlow, run
`php artisan boost:update --discover` to scan newly installed packages and
choose the WorkFlow resources. Boost can help inspect the published schema,
store configuration, and host integration, but it does not provide your step
executors or authorization. See Laravel's guidance for
[package guidelines](https://laravel.com/framework/docs/boost#third-party-package-ai-guidelines)
and [package skills](https://laravel.com/framework/docs/boost#third-party-package-skills).
