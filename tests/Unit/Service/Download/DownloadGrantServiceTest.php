<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Download;

use App\Service\Download\DownloadGrantService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for HMAC download grants.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class DownloadGrantServiceTest extends TestCase
{
    /**
     * @brief Minted grant parses back with expected fields.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testMintAndParseRoundTrip(): void
    {
        $svc = new DownloadGrantService('test-secret', 3600);
        $token = $svc->mint(
            DownloadGrantService::KIND_FILE,
            '42',
            DownloadGrantService::ACTOR_USER,
            '7',
            '"f-42-abc"',
        );
        $parsed = $svc->parse($token, DownloadGrantService::KIND_FILE, '42');
        self::assertNotNull($parsed);
        self::assertSame('42', $parsed['resourceId']);
        self::assertSame('7', $parsed['actorId']);
        self::assertSame('"f-42-abc"', $parsed['fingerprint']);
    }

    /**
     * @brief Tampered grant is rejected.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testTamperedGrantRejected(): void
    {
        $svc = new DownloadGrantService('test-secret', 3600);
        $token = $svc->mint(DownloadGrantService::KIND_FILE, '1', DownloadGrantService::ACTOR_USER, '1');
        $tampered = substr($token, 0, -4).'xxxx';
        self::assertNull($svc->parse($tampered));
    }

    /**
     * @brief Empty grant is rejected (unlike legacy DownloadTokenService).
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testEmptyGrantRejected(): void
    {
        $svc = new DownloadGrantService('test-secret', 3600);
        self::assertNull($svc->parse(''));
    }
}
