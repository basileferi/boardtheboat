<?php

declare(strict_types=1);

namespace App\Enum;

enum ContractType: string
{
    case Internship = 'internship';
    case Apprenticeship = 'apprenticeship';
    case FixedTerm = 'fixed_term';
    case Permanent = 'permanent';
    case Freelance = 'freelance';
    case Other = 'other';
}
