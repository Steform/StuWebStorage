<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\ServerDiskSpaceService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for server disk space snapshot service.
 */
final class ServerDiskSpaceServiceTest extends TestCase
{
    /**
     * @brief Snapshot returns positive free and total bytes for project directory.
     *
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function testBuildSnapshotReturnsDiskMetrics(): void
    {
        $projectDir = dirname(__DIR__, 4);
        $service = new ServerDiskSpaceService($projectDir);

        $snapshot = $service->buildSnapshot();

        self::assertTrue($snapshot['available']);
        self::assertIsInt($snapshot['freeBytes']);
        self::assertIsInt($snapshot['totalBytes']);
        self::assertIsInt($snapshot['usedBytes']);
        self::assertIsInt($snapshot['usedPercent']);
        self::assertGreaterThan(0, $snapshot['freeBytes']);
        self::assertGreaterThan(0, $snapshot['totalBytes']);
        self::assertGreaterThanOrEqual(0, $snapshot['usedPercent']);
        self::assertLessThanOrEqual(100, $snapshot['usedPercent']);
    }
}
