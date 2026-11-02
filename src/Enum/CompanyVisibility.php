<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who may see a company. A company a user typed in stays private until they
 * suggest it and an admin promotes it to the shared catalogue.
 */
enum CompanyVisibility: string
{
    case Private = 'private';
    case Suggested = 'suggested';
    case Catalogue = 'catalogue';
}
