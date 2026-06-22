<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Share;

use App\Service\Share\PublicSharePreAuthTokenService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for signed pre-auth tokens used before public share password entry.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class PublicSharePreAuthTokenServiceTest extends TestCase
{
    /**
     * @brief Minted token round-trips to the same challenge id and public token.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testMintAndVerifyReturnsPayload(): void
    {
        $svc = new PublicSharePreAuthTokenService('test-secret-32bytes-min-pref', 900);
        $token = $svc->mint(42, 'pubtok');
        $out = $svc->verify($token);
        self::assertIsArray($out);
        self::assertSame(42, $out['challengeId']);
        self::assertSame('pubtok', $out['publicToken']);
    }

    /**
     * @brief Bad or empty tokens verify as null.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testVerifyRejectsEmptyAndGarbage(): void
    {
        $svc = new PublicSharePreAuthTokenService('another-secret', 60);
        self::assertNull($svc->verify(''));
        self::assertNull($svc->verify(null));
        self::assertNull($svc->verify('not-a-valid-token!!!'));
    }
}
