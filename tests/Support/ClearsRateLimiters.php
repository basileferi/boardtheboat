<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Empties the rate-limiter cache before each test.
 *
 * The limiters are deliberately strict (five sign-ups or reset requests per hour and per IP),
 * and the pool lives on the filesystem, so without this a full test run — or simply running the
 * suite twice — would start tripping limits and produce failures that have nothing to do with
 * the behaviour under test.
 */
trait ClearsRateLimiters
{
    #[Before]
    protected function clearRateLimiterState(): void
    {
        static::bootKernel();

        /** @var AdapterInterface $pool */
        $pool = static::getContainer()->get('cache.rate_limiter');
        $pool->clear();

        static::ensureKernelShutdown();
    }
}
