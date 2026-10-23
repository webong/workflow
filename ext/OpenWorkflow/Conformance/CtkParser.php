<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Conformance;

use RuntimeException;

/**
 * Minimal Gherkin parser for the Serverless Workflow CTK feature format.
 *
 * Supports exactly the step patterns the pinned CTK (specification@v1.0.3) uses:
 * - "Given a workflow with definition:" + """yaml ... """ docstring
 * - "And given the workflow input is:" + docstring (also indented variants)
 * - "When the workflow is executed"
 * - Then/And assert steps (kept verbatim for classification)
 */
final class CtkParser
{
    /**
     * Parse one .feature file's contents into scenarios.
     *
     * @return list<CtkScenario>
     */
    public function parse(string $contents, string $featureName): array
    {
        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $scenarios = [];
        $current = null;

        $inDefinition = false;
        $inInput = false;
        $inOutput = false;
        $captureNextAs = null; // 'definition' | 'input' | 'output'
        $buffer = [];
        $currentIndex = null; // index of the in-flight scenario in $scenarios

        $sync = static function () use (&$scenarios, &$currentIndex, &$current): void {
            if ($currentIndex !== null) {
                $scenarios[$currentIndex] = $current;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Docstring boundaries: trimmed line begins with triple quotes
            if (str_starts_with($trimmed, '"""')) {
                if ($captureNextAs !== null) {
                    // Opening fence — start collecting
                    $inDefinition = $captureNextAs === 'definition';
                    $inInput = $captureNextAs === 'input';
                    $inOutput = $captureNextAs === 'output';
                    $captureNextAs = null;
                    $buffer = [];
                    continue;
                }
                // Closing fence
                if ($inDefinition) {
                    $current = $this->withDefinition($current, $this->dedent($buffer), $featureName);
                    $inDefinition = false;
                    $buffer = [];
                    $sync();
                    continue;
                }
                if ($inInput) {
                    $current = $this->withInput($current, $this->dedent($buffer));
                    $inInput = false;
                    $buffer = [];
                    $sync();
                    continue;
                }
                if ($inOutput) {
                    $current = $this->withExpectedOutput($current, $this->dedent($buffer));
                    $inOutput = false;
                    $buffer = [];
                    $sync();
                    continue;
                }
                continue;
            }

            if ($inDefinition || $inInput || $inOutput) {
                $buffer[] = $line;
                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(Feature|Scenario Outline|Examples):/', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^Scenario:\s*(.+)$/', $trimmed, $m) === 1) {
                $current = ['name' => trim($m[1]), 'definition' => null, 'input' => null, 'asserts' => [], 'output' => null];
                $scenarios[] = $current;
                $currentIndex = count($scenarios) - 1;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^(Given|And)\s+a workflow with definition\s*:\s*$/i', $trimmed) === 1) {
                $captureNextAs = 'definition';
                continue;
            }

            if (preg_match('/^(And\s+)?given the workflow input is\s*:\s*$/i', $trimmed) === 1) {
                $captureNextAs = 'input';
                continue;
            }

            if (preg_match('/^(Then|And)\s+.*should complete with output\s*:\s*$/i', $trimmed) === 1) {
                // Keep the step as an assert (preserves requiresRuntime detection)
                // and capture the following docstring as the expected output.
                $current['asserts'][] = $trimmed;
                $sync();
                $captureNextAs = 'output';
                continue;
            }

            if (preg_match('/^(When|Then|And)\s+(.*)$/i', $trimmed, $m) === 1) {
                $keyword = strtolower($m[1]);
                $text = trim($m[2]);
                if ($keyword === 'when' && stripos($text, 'the workflow is executed') === 0) {
                    continue;
                }
                // Everything after "When the workflow is executed" is an assert step
                if ($keyword === 'then' || ($keyword === 'and' && $current['definition'] !== null)) {
                    $current['asserts'][] = $text;
                    $sync();
                }
            }
        }

        $out = [];
        foreach ($scenarios as $s) {
            if (! is_string($s['definition']) || $s['definition'] === '') {
                continue; // scenario without a definition is not testable here
            }
            $out[] = new CtkScenario(
                feature: $featureName,
                name: $s['name'],
                definition: $s['definition'],
                input: $s['input'],
                assertSteps: $s['asserts'],
                expectedOutput: $s['output'] ?? null,
            );
        }

        return $out;
    }

    /**
     * Parse every .feature file in a directory.
     *
     * @return list<CtkScenario>
     */
    public function parseDirectory(string $directory): array
    {
        $scenarios = [];
        foreach (glob(rtrim($directory, '/') . '/*.feature') ?: [] as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException("Could not read {$file}");
            }
            $name = basename($file, '.feature');
            array_push($scenarios, ...$this->parse($contents, $name));
        }
        return $scenarios;
    }

    /**
     * Strip the common leading indentation from collected docstring lines,
     * as Gherkin docstrings preserve only relative indentation.
     *
     * @param list<string> $lines
     */
    private function dedent(array $lines): string
    {
        $nonEmpty = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
        if ($nonEmpty === []) {
            return '';
        }
        $min = min(array_map(static fn (string $l): int => strlen($l) - strlen(ltrim($l)), $nonEmpty));
        $out = array_map(
            static fn (string $l): string => $min > 0 ? (substr($l, 0, $min) === str_repeat(' ', $min) ? substr($l, $min) : ltrim($l)) : $l,
            $lines,
        );
        return trim(implode("\n", $out));
    }

    /**
     * @param array{name: string, definition: ?string, input: ?array, asserts: list<string>, output: ?array}|null $current
     * @return array{name: string, definition: ?string, input: ?array, asserts: list<string>, output: ?array}
     */
    private function withDefinition(?array $current, string $yaml, string $featureName): array
    {
        if ($current === null) {
            $current = ['name' => "(unnamed in {$featureName})", 'definition' => null, 'input' => null, 'asserts' => [], 'output' => null];
        }
        $current['definition'] = $yaml;
        return $current;
    }

    /**
     * @param array{name: string, definition: ?string, input: ?array, asserts: list<string>, output: ?array}|null $current
     * @return array{name: string, definition: ?string, input: ?array, asserts: list<string>, output: ?array}
     */
    private function withExpectedOutput(?array $current, string $yaml): array
    {
        if ($current === null) {
            $current = ['name' => "(unnamed in {$featureName})", 'definition' => null, 'input' => null, 'asserts' => [], 'output' => null];
        }
        $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
        $current['output'] = is_array($parsed) ? $parsed : null;
        return $current;
    }

    /**
     * @param array{name: string, definition: ?string, input: ?array, asserts: list<string>, output: ?array}|null $current
     * @return array{name: string, definition: ?string, input: ?array, asserts: list<string>, output: ?array}
     */
    private function withInput(?array $current, string $yaml): array
    {
        if ($current === null) {
            return $current ?? ['name' => '(unnamed)', 'definition' => null, 'input' => null, 'asserts' => [], 'output' => null];
        }
        $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
        $current['input'] = is_array($parsed) ? $parsed : null;
        return $current;
    }
}