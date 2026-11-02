<?php

declare(strict_types=1);

namespace App\Enum;

enum ImportBatchStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
