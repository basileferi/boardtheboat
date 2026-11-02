<?php

declare(strict_types=1);

namespace App\Enum;

enum WorkMode: string
{
    case Onsite = 'onsite';
    case Remote = 'remote';
    case Hybrid = 'hybrid';
    case Unknown = 'unknown';
}
