<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Head-count bracket, mapped from the INSEE "tranche d'effectif" ranges.
 */
enum CompanySize: string
{
    case Unknown = 'unknown';
    case Micro = 'micro';          // 1-9
    case Small = 'small';          // 10-49
    case Medium = 'medium';        // 50-249
    case Large = 'large';          // 250-4999
    case VeryLarge = 'very_large'; // 5000+
}
