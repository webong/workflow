<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Conditions;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Conditions\RuleMatcher;
use Webong\WorkFlow\Rules\RuleSet;

final class ConditionCompilerTest extends TestCase
{
    public function testRulesAndJqShareTheSameMatchInterface(): void
    {
        $compiler = new ConditionCompiler();
        foreach ([['version'=>'v1','rules'=>['amount'=>'required|numeric|min:100']], ['syntax'=>'jq','expression'=>'.amount >= 100']] as $definition) {
            $condition = $compiler->compile($definition);
            self::assertTrue($condition->matches(['amount'=>125]));
            self::assertFalse($condition->matches(['amount'=>50]));
        }
    }

    public function testLaravelMissingNullWildcardAndCoercionSemanticsArePreserved(): void
    {
        $matcher = new RuleMatcher();
        self::assertTrue($matcher->matches([], RuleSet::make(['optional'=>'string'])));
        self::assertFalse($matcher->matches([], RuleSet::make(['required'=>'required'])));
        self::assertTrue($matcher->matches(['value'=>null], RuleSet::make(['value'=>'nullable|string'])));
        self::assertTrue($matcher->matches(['count'=>'12'], RuleSet::make(['count'=>'integer|min:10'])));
        self::assertTrue($matcher->matches(['items'=>[]], RuleSet::make(['items.*.id'=>'required'])));
        self::assertFalse($matcher->matches(['items'=>[]], RuleSet::make(['items'=>'required|array|min:1','items.*.id'=>'required'])));
    }

    public function testBooleanCompositionAndErrorsAreDistinct(): void
    {
        $compiler = new ConditionCompiler();
        $yes = ['syntax'=>'jq','expression'=>'true'];
        $no = ['syntax'=>'jq','expression'=>'false'];
        self::assertTrue($compiler->compile(['all'=>[$yes,['not'=>$no]]])->matches([]));
        self::assertTrue($compiler->compile(['any'=>[$no,$yes]])->matches([]));
        self::assertFalse($compiler->compile(['all'=>[$yes,$no]])->matches([]));
        $badResult = $compiler->compile(['not'=>['syntax'=>'jq','expression'=>'.amount']])->evaluate(['amount'=>125]);
        self::assertTrue($badResult->isError());
        self::assertFalse($badResult->matched());
        self::assertNull($badResult->value);
    }

    public function testInvalidUnvisitedBranchesAreStillRejectedDuringCompilation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConditionCompiler())->compile(['any'=>[['syntax'=>'jq','expression'=>'true'],['rules'=>['id'=>'exists:users']]]]);
    }

    public function testStrictBooleanJqAndRuntimeFailureNeverBecomeTruthyMatches(): void
    {
        $compiler = new ConditionCompiler();
        foreach (['null','1','[]','true, false'] as $expression) {
            $result = $compiler->compile(['syntax'=>'jq','expression'=>$expression])->evaluate([]);
            self::assertTrue($result->isError());
            self::assertFalse($result->matched());
        }
        self::assertTrue($compiler->compile(['rules'=>['id'=>'required']])->evaluate('wrong-shape')->isError());
    }

    public function testUnknownVersionsSyntaxExtraFieldsAndDeepTreesAreRejected(): void
    {
        $deep = ['syntax'=>'jq','expression'=>'true'];
        for ($i=0;$i<34;$i++) { $deep=['not'=>$deep]; }
        foreach ([['version'=>null,'rules'=>[]],['syntax'=>null,'rules'=>[]],['version'=>'v2','rules'=>[]],['syntax'=>'php','expression'=>'anything'],['all'=>[]],['any'=>[['rules'=>[]]],'extra'=>true],$deep] as $definition) {
            try { (new ConditionCompiler())->compile($definition); self::fail('Invalid condition accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testWideAndNumerousTreesAreBounded(): void
    {
        $leaf = ['rules' => []];
        foreach ([['any' => array_fill(0, 65, $leaf)], ['all' => array_fill(0, 5, ['all' => array_fill(0, 64, $leaf)])]] as $definition) {
            try {
                (new ConditionCompiler())->compile($definition);
                self::fail('Oversized condition accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

}
