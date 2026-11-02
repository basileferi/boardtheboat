<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of a CompanyBrief. Phase 1 only ever writes Pending: generation lands in Phase 2.
 */
enum BriefStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
