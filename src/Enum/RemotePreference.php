<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the candidate is willing to accept. "Any" disables the criterion in the scorer.
 */
enum RemotePreference: string
{
    case Any = 'any';
    case Onsite = 'onsite';
    case Remote = 'remote';
    case Hybrid = 'hybrid';
}
