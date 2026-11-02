<?php

declare(strict_types=1);

namespace App\Enum;

enum OfferStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Expired = 'expired';
}
