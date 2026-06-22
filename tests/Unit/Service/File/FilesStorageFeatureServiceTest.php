<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FilesStorageFeatureService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for the files storage feature flag service.
 */
final class FilesStorageFeatureServiceTest extends TestCase
{
    /**
     * @brief Enabled configuration must report true.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testIsEnabledWhenConfiguredTrue(): void
    {
        $service = new FilesStorageFeatureService(true);

        self::assertTrue($service->isEnabled());
    }

    /**
     * @brief Disabled configuration must report false.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testIsEnabledWhenConfiguredFalse(): void
    {
        $service = new FilesStorageFeatureService(false);

        self::assertFalse($service->isEnabled());
    }
}
