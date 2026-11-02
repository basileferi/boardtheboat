<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the application was sent. Deliberately coarse: we store no named individual.
 */
enum ContactChannel: string
{
    case Email = 'email';
    case WebForm = 'web_form';
    case JobBoard = 'job_board';
    case Event = 'event';
    case Referral = 'referral';
    case Other = 'other';
}
