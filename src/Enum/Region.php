<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The France / International switch. Persisted on the Profile and overridable per search.
 */
enum Region: string
{
    case France = 'france';
    case International = 'international';
}
