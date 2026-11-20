<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Conditions;

use Webong\WorkFlow\Conditions\ConditionAuthoringValidator;
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Jq\EvaluatorBackend;
use Webong\WorkFlow\Jq\JqConditionDriver;
use Webong\WorkFlow\Jq\ZestJqEvaluator;
use Webong\WorkFlow\Laravel\RuleConditionDriver;

final class ConditionFixtures
{
    public static function compiler(?EvaluatorBackend $jq = null): ConditionCompiler
    {
        return new ConditionCompiler([new RuleConditionDriver(), new JqConditionDriver($jq ?? new ZestJqEvaluator())]);
    }

    public static function authoring(): ConditionAuthoringValidator
    {
        return new ConditionAuthoringValidator(self::compiler());
    }
}
