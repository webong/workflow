<?php
final class State {
    public function withStep(State $next): State { return $next; }
}
final class Step {}
function execute(Step $step): State { return new State(); }
function run(array $steps, State $state): void {
    foreach ($steps as $step) {
        if (! $step instanceof Step) { throw new RuntimeException('bad'); }
        $result = execute($step);
        $state = $state->withStep($result);
    }
}
run([new Step()], new State());
