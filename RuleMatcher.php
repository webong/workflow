<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Webong\WorkFlow\Rules\RuleSet;

final readonly class RuleMatcher
{
    private ValidationFactory $validator;

    public function __construct(?ValidationFactory $validator = null)
    {
        $this->validator = $validator ?? new Factory(new Translator(new ArrayLoader(), 'en'));
    }

    /** @param array<mixed> $data */
    public function matches(array $data, RuleSet $rules): bool
    {
        return ! $this->validator->make($data, $rules->rules)->fails();
    }
}
