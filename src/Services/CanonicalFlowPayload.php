<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;

/** JSON object order and integral float formatting are not domain changes. */
final class CanonicalFlowPayload
{
    public static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::normalize(...), $value);
        }

        if (is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException('Flow payloads must contain only JSON-compatible values.');
        }

        if (is_float($value) && $value >= PHP_INT_MIN && $value < PHP_INT_MAX && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }

    public static function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(self::normalize($value), JSON_THROW_ON_ERROR));
    }
}
