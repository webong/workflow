<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Rules\RuleSet;

final class RuleSetTest extends TestCase
{
    public function testPortableFieldsPipeNormalizationAndRoundTrip(): void
    {
        $rules = RuleSet::make([' customer.amount ' => 'required|numeric|min:100', 'Headers.X-Event' => ['string']]);
        self::assertSame(['required', 'numeric', 'min:100'], $rules->rules['customer.amount']);
        self::assertArrayHasKey('Headers.X-Event', $rules->rules);
        self::assertSame($rules->toArray(), RuleSet::fromArray($rules->toArray())->toArray());
        self::assertTrue(RuleSet::any()->matchesEverything());
    }

    public function testUnsafeUnknownMalformedAndUnboundedRulesAreRejected(): void
    {
        foreach ([['id'=>'exists:users,id'], ['id'=>'unique:users'], ['id'=>'regex:/x/'], ['id'=>[new \stdClass()]], ['id'=>'min:1e100'], ['id'=>'between:10,1'], ['a..b'=>'required'], ['id'=>array_fill(0,17,'string')]] as $rules) {
            try { RuleSet::make($rules); self::fail('Unsafe/malformed rules accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testVersionAndEnvelopeAreClosed(): void
    {
        foreach ([['version'=>'v2','rules'=>[]], ['version'=>'v1','rules'=>[],'class'=>'custom'], ['version'=>'v1','rules'=>'required']] as $document) {
            try { RuleSet::fromArray($document); self::fail('Invalid rule envelope accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
