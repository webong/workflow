<?php

declare(strict_types=1);

/*
 * Bootstrap file for elephc compilation.
 *
 * This file explicitly requires the native protocol dependencies so compile-time
 * autoloading can resolve them. elephc follows require/include statements
 * but does not use vendor/autoload.php for type-hint-only classes.
 *
 * Only the elephc entries load these compiler-specific builtin declarations.
 *
 * Paths are relative to this file's location: mod/elephc/php/
 * Source is at ../../../src from here (mod/elephc/php -> mod/elephc -> mod -> workspace -> src)
 */

// elephc-specific polyfills for missing builtins/constants
// These must be defined before requiring core files so the type checker sees them.

const DATE_ATOM = 'Y-m-d\TH:i:sP';


function random_bytes(int $length): string
{
    $bytes = '';
    for ($i = 0; $i < $length; $i++) {
        $bytes .= chr(random_int(0, 255));
    }
    return $bytes;
}

// Core contracts
require_once __DIR__.'/../../../src/Contracts/AtomicFlowStateStore.php';
require_once __DIR__.'/../../../src/Contracts/FlowActionAuthorizer.php';
require_once __DIR__.'/../../../src/Contracts/FlowActionHandler.php';
require_once __DIR__.'/../../../src/Contracts/FlowActionRegistry.php';
require_once __DIR__.'/../../../src/Contracts/FlowContext.php';
require_once __DIR__.'/../../../src/Contracts/FlowMutableContext.php';
require_once __DIR__.'/../../../src/Contracts/FlowDeferredCompletionHandler.php';
require_once __DIR__.'/../../../src/Contracts/FlowDefinitionProvider.php';
require_once __DIR__.'/../../../src/Contracts/FlowEventSink.php';
require_once __DIR__.'/../../../src/Contracts/FlowExecutionDriver.php';
require_once __DIR__.'/../../../src/Contracts/FlowFailureReporter.php';
require_once __DIR__.'/../../../src/Contracts/FlowPresentationResolver.php';
require_once __DIR__.'/../../../src/Contracts/FlowStateMigrator.php';
require_once __DIR__.'/../../../src/Contracts/FlowStateSerializer.php';
require_once __DIR__.'/../../../src/Contracts/FlowStateStore.php';
require_once __DIR__.'/../../../src/Contracts/FlowStateStoreFactory.php';
require_once __DIR__.'/../../../src/Contracts/FlowStepExecutor.php';
require_once __DIR__.'/../../../src/Contracts/ForgettableFlowStateStore.php';

// Enums
require_once __DIR__.'/../../../src/Enums/FlowEventType.php';
require_once __DIR__.'/../../../src/Enums/FlowExecutionStatus.php';
require_once __DIR__.'/../../../src/Enums/FlowStatus.php';
require_once __DIR__.'/../../../src/Enums/FlowStepStatus.php';

// Services
require_once __DIR__.'/../../../src/Services/CanonicalFlowPayload.php';
require_once __DIR__.'/../../../src/Services/CollectingFlowEventSink.php';
require_once __DIR__.'/../../../src/Services/DefaultFlowStateSerializer.php';
require_once __DIR__.'/../../../src/Services/FlowEvaluator.php';
require_once __DIR__.'/../../../src/Services/FlowStepExecution.php';
require_once __DIR__.'/../../../src/Services/FlowRunner.php';
require_once __DIR__.'/../../../src/Services/FlowStateTransition.php';
require_once __DIR__.'/../../../src/Services/NullFlowEventSink.php';
require_once __DIR__.'/../../../src/Services/NullFlowFailureReporter.php';

// Value objects
require_once __DIR__.'/../../../src/ValueObjects/ArrayFlowContext.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowAction.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowActionContext.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowDeferredCompletion.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowDefinition.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowEvent.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowExecutionReceipt.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowExecutionRequest.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowPresentation.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowRetryPolicy.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowRun.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowRunResult.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowState.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowStateSubject.php';
require_once __DIR__.'/../../../src/ValueObjects/FlowStepDefinition.php';
require_once __DIR__.'/../../../src/ValueObjects/StepResult.php';
require_once __DIR__.'/../../../src/ValueObjects/StepState.php';

// Native protocol
require_once __DIR__.'/../../native/php/NativeFlow.php';
require_once __DIR__.'/../../native/php/api.php';