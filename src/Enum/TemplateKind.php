<?php

declare(strict_types=1);

namespace App\Enum;

enum TemplateKind: string
{
    case Letter = 'letter';
    case Cv = 'cv';
    case Followup = 'followup';
}
