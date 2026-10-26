<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Enums;

enum FlowDiagnosticSeverity: string
{
    case ERROR = 'error';
    case WARNING = 'warning';
    case INFO = 'info';
}
