<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Share;

use App\Service\Share\PublicSharePasswordRateLimiterService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @brief Unit tests for public share password attempt rate limiting.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class PublicSharePasswordRateLimiterServiceTest extends TestCase
{
    /**
     * @brief Third failed attempt sets lock; clearFailures clears counter but lock remains.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testLockAfterThreeFailures(): void
    {
        $cache = new ArrayAdapter();
        $svc = new PublicSharePasswordRateLimiterService($cache);
        $t = 'token-abc';
        $ip = '10.0.0.1';
        self::assertFalse($svc->isLocked($t, $ip));
        $svc->recordFailure($t, $ip);
        $svc->recordFailure($t, $ip);
        self::assertFalse($svc->isLocked($t, $ip));
        $svc->recordFailure($t, $ip);
        self::assertTrue($svc->isLocked($t, $ip));
        $svc->clearFailures($t, $ip);
        self::assertTrue($svc->isLocked($t, $ip));
    }
}
