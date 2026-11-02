<?php

declare(strict_types=1);

namespace App\Enum;

enum PipelineRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
