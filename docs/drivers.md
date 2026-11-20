# Core drivers

Core requires PHP only and provides explicit extension interfaces. Installing an adapter never enables its engine automatically.

| Driver | Package | Core interface | Role |
| --- | --- | --- | --- |
| `OwsDefinitionDriver` | `webong/workflow-open-workflow` | `FlowDefinitionDriver` | Import OWS JSON/YAML into a canonical `FlowDefinition` |
| `JqConditionDriver` | `webong/workflow-jq` | `ConditionDriver` | Compile/evaluate jq predicates |
| `RuleConditionDriver` | `webong/workflow-illuminate` | `ConditionDriver` | Compile/evaluate bounded Illuminate rules |

Definition-language drivers and condition drivers have different inputs and results. Execution drivers (inline, queue and Temporal), state stores and step executors retain their existing contracts.

```php
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Illuminate\RuleConditionDriver;
use Webong\WorkFlow\Jq\JqConditionDriver;
use Webong\WorkFlow\OpenWorkflow\OwsDefinitionDriver;
use Webong\WorkFlow\Services\FlowDefinitionImporter;

$definitions = new FlowDefinitionImporter([new OwsDefinitionDriver()]);
$conditions = new ConditionCompiler([new JqConditionDriver(), new RuleConditionDriver()]);

$import = $definitions->import('ows', $jsonOrYaml);
if (! $import->isValid()) {
    // Return the structured $import->diagnostics to the definition author.
    return;
}
$definition = $import->definition;
```

## Definition driver interface

`Contracts\FlowDefinitionDriver` exposes `format(): string` and `import(string $document): FlowDefinitionImportResult`. Format names must be valid identifiers and unique in an importer. `formats()` lists explicitly enabled languages. Core does not guess a language or discover installed packages.

An import result contains a canonical definition, structured `FlowDiagnostic` findings and driver metadata. Errors always discard the candidate definition; a result without a definition always carries a blocking diagnostic. Warnings alone do not block a successful import. `toArray()` exposes `valid`, `flow_definition`, `diagnostics` and `metadata`.

Unknown formats return `definition_driver_unavailable`; unexpected engine exceptions return a safe `definition_driver_failure` diagnostic. Core never returns raw exception details or falls back to another driver.

The OpenWorkflow driver uses the existing OWS validator/translator, retains the pinned specification version and supported-feature metadata, and converts unsupported constructs into blocking diagnostics at their reported paths. Its legacy `OpenWorkflow` facade and capability-report methods remain available, but the core hook never exposes a partially translated definition as usable.

Importing does not persist, activate, authorize or execute a workflow. Validate the resulting definition against the application's executors/capabilities before accepting it. OWS runtime operations still use the existing `FlowStepExecutor` interface, with explicitly supplied OWS contexts and executors. A definition driver does not grant network, script, event or scheduling capabilities.

## Laravel uses the core hook

The Laravel adapter supplies application/state integration; it neither imports nor auto-registers OpenWorkflow. Application bootstrap chooses the concrete driver and binds the generic core importer:

```php
// In your application's provider:
$this->app->singleton(FlowDefinitionImporter::class, static fn () =>
    new FlowDefinitionImporter([new OwsDefinitionDriver()])
);
```

Application code then depends on `FlowDefinitionImporter` to import documents. It can store the resulting core definition/run using the ordinary Laravel adapter. Replacing or adding a definition language changes the bootstrap registry, not the Laravel adapter. jq and rules are registered separately in a shared condition compiler used by authoring and guards.

The new definition hook and OpenWorkflow adapter require core `^0.1.0-rc.4`. Publication is separate from these source changes; existing rc.3 artifacts remain immutable. See [conditions](conditions.md), [OWS](open-workflow-specification.md) and [Laravel](laravel.md) for each integration.
