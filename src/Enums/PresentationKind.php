<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Enums;

enum PresentationKind: string
{
    case BANNER = 'banner';
    case CARD = 'card';
    case INLINE = 'inline';
    case TOAST = 'toast';
}
