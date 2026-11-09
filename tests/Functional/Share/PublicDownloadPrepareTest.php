<?php

declare(strict_types=1);

namespace App\Tests\Functional\Share;

use App\Controller\PublicDownloadController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @brief Contract checks for public streaming download flow.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class PublicDownloadPrepareTest extends TestCase
{
    /**
     * @brief Public download no longer exposes prepare tick/deliver endpoints.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testPublicPrepareRoutesRemoved(): void
    {
        $ref = new ReflectionClass(PublicDownloadController::class);
        $path = $ref->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);

        self::assertStringContainsString('encryptedStreamDeliveryService->buildEncryptedStreamResponse', $source);
        self::assertStringContainsString("'prepareRequired' => false", $source);
        self::assertStringNotContainsString('download_public_prepare_tick', $source);
        self::assertStringNotContainsString('download_public_prepare_deliver', $source);
    }
}
