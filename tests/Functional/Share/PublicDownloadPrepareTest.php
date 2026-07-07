<?php

declare(strict_types=1);

namespace App\Tests\Functional\Share;

use App\Controller\PublicDownloadController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @brief Contract checks for public prepared download flow.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class PublicDownloadPrepareTest extends TestCase
{
    /**
     * @brief Public prepared download endpoints and flags exist in controller source.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testPublicPrepareFlowContract(): void
    {
        $ref = new ReflectionClass(PublicDownloadController::class);
        $path = $ref->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);

        self::assertStringContainsString('prepareRequired', $source);
        self::assertStringContainsString('download_public_prepare_tick', $source);
        self::assertStringContainsString('download_public_prepare_deliver', $source);
        self::assertStringContainsString('loadAuthorizedPublicFileTicket', $source);
        self::assertStringContainsString('deleteItem($this->ticketCacheKey($downloadKey))', $source);
    }
}
