<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (! function_exists('now')) {
    function now(DateTimeZone|string|int|null $timezone = null): Carbon\CarbonInterface
    {
        return Carbon\CarbonImmutable::now($timezone);
    }
}

if (! function_exists('config')) {
    function config(string|array|null $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}
