<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Result of the asynchronous reachability check run against a company career page.
 * "Blocked" means robots.txt disallows us, which is a permanent skip, not a failure.
 */
enum CareerPageStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Blocked = 'blocked';
}
