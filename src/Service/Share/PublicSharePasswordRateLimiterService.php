<?php

declare(strict_types=1);

namespace App\Service\Share;

use Psr\Cache\CacheItemPoolInterface;

/**
 * @brief After 3 failed share-password attempts, block further attempts for 5 minutes (per IP + public token).
 * @author Stephane H.
 * @date 2026-05-04
 */
final class PublicSharePasswordRateLimiterService
{
    private const FAIL_PREFIX = 'pub_pwd_fail.';

    private const LOCK_PREFIX = 'pub_pwd_lock.';

    private const MAX_FAILS = 3;

    private const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @brief Whether the key is under an active lockout.
     * @param string $publicToken Public share token.
     * @param string $clientIp Client IP.
     * @return bool True when locked.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function isLocked(string $publicToken, string $clientIp): bool
    {
        $k = $this->lockKey($publicToken, $clientIp);
        $item = $this->cache->getItem($k);

        return $item->isHit();
    }

    /**
     * @brief Record a failed password attempt; may set lock at threshold.
     * @param string $publicToken Public share token.
     * @param string $clientIp Client IP.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function recordFailure(string $publicToken, string $clientIp): void
    {
        $fk = $this->failKey($publicToken, $clientIp);
        $item = $this->cache->getItem($fk);
        $count = $item->isHit() ? (int) $item->get() + 1 : 1;
        $item->set($count);
        $item->expiresAfter(self::LOCK_TTL_SECONDS);
        $this->cache->save($item);

        if ($count >= self::MAX_FAILS) {
            $lk = $this->lockKey($publicToken, $clientIp);
            $lockItem = $this->cache->getItem($lk);
            $lockItem->set(1);
            $lockItem->expiresAfter(self::LOCK_TTL_SECONDS);
            $this->cache->save($lockItem);
        }
    }

    /**
     * @brief Clear failure counter after successful password verification.
     * @param string $publicToken Public share token.
     * @param string $clientIp Client IP.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function clearFailures(string $publicToken, string $clientIp): void
    {
        $this->cache->deleteItem($this->failKey($publicToken, $clientIp));
    }

    private function failKey(string $publicToken, string $clientIp): string
    {
        return self::FAIL_PREFIX.hash('sha256', $publicToken.'|'.$clientIp);
    }

    private function lockKey(string $publicToken, string $clientIp): string
    {
        return self::LOCK_PREFIX.hash('sha256', $publicToken.'|'.$clientIp);
    }
}
