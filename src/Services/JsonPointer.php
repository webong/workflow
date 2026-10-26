<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;

/**
 * RFC 6901 JSON Pointer resolution over plain JSON-compatible documents.
 *
 * The empty pointer selects the whole document. `~0` and `~1` escapes are
 * unescaped per the RFC (`~1` first, then `~0`). List indexes accept the
 * canonical decimal form only; object keys are matched exactly.
 *
 * The result shape is `[bool $found, mixed $value]` so a resolved JSON null
 * stays distinguishable from an unresolved pointer.
 */
final class JsonPointer
{
    /**
     * @return array{0: bool, 1: mixed}
     */
    public static function resolve(mixed $document, string $pointer): array
    {
        if ($pointer === '') {
            return [true, $document];
        }

        if (! str_starts_with($pointer, '/')) {
            throw new InvalidArgumentException("A JSON Pointer must be empty or start with '/', got '{$pointer}'.");
        }

        $value = $document;
        foreach (explode('/', substr($pointer, 1)) as $rawToken) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $rawToken);

            if (! is_array($value)) {
                return [false, null];
            }

            if (array_is_list($value)) {
                if (preg_match('/^(0|[1-9][0-9]*)$/', $token) !== 1 || (int) $token >= count($value)) {
                    return [false, null];
                }
                $value = $value[(int) $token];
                continue;
            }

            if (! array_key_exists($token, $value)) {
                return [false, null];
            }
            $value = $value[$token];
        }

        return [true, $value];
    }

    /** Escapes one reference token so it can be used in a JSON Pointer. */
    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
