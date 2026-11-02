<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where an Application stands. Reaching Applied schedules a follow-up; reaching
 * Interview, Rejected or Offer cancels every pending reminder.
 */
enum PipelineStatus: string
{
    case Saved = 'saved';
    case ToApply = 'to_apply';
    case Applied = 'applied';
    case Interview = 'interview';
    case Rejected = 'rejected';
    case Offer = 'offer';

    public function isClosed(): bool
    {
        return self::Rejected === $this || self::Offer === $this;
    }

    public function stopsReminders(): bool
    {
        return \in_array($this, [self::Interview, self::Rejected, self::Offer], true);
    }
}
