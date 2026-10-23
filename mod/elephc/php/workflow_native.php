<?php

declare(strict_types=1);

/*
 * elephc shared-library entry point.
 *
 *   elephc --emit cdylib mod/elephc/php/workflow_native.php
 *
 * elephc generates libworkflow_native.{dylib,so} and libworkflow_native.h from
 * the #[Export] functions below. Top-level statements do not run when the
 * library is loaded; the host drives it through the exported function.
 *
 * The bootstrap file explicitly requires all core classes so elephc's
 * compile-time autoloading can resolve type-hint-only classes.
 */

require_once __DIR__.'/bootstrap.php';

/**
 * C ABI: one UTF-8 JSON request in, one UTF-8 JSON response out.
 *
 * The generated header is authoritative for the exact prototype; it is the
 * fixed-parameter string-return wrapper described in the elephc docs, with the
 * caller-owned output pointers appended. Domain errors are ordinary `ok: false`
 * JSON, not ABI statuses. Release a successful result with elephc_free().
 */
#[Export]
function workflow_native_call(string $request): string
{
    return workflow_native_dispatch($request);
}
