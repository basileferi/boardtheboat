<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks for a refresh of every enabled job and company source.
 *
 * Dispatched nightly by App\Schedule and by the admin "refresh now" button. The handler is
 * idempotent: offers and companies are upserted on their source keys, so a replay after a
 * crash or a retry only updates rows.
 */
final readonly class RefreshSourcesMessage
{
    public function __construct(
        public string $trigger = 'scheduler',
    ) {
    }
}
