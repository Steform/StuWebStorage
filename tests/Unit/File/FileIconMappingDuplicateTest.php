<?php

declare(strict_types=1);

namespace App\Tests\Unit\File;

use App\File\FileIconMappingProvider;
use PHPUnit\Framework\TestCase;

/**
 * @brief Ensures YAML mappings load without duplicate extension keys.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileIconMappingDuplicateTest extends TestCase
{
    /**
     * @brief Provider loads all mapping files without duplicate extensions.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testMappingsLoadWithoutDuplicateExtensions(): void
    {
        $root = dirname(__DIR__, 3);
        $provider = new FileIconMappingProvider(
            $root.'/config/icons/mappings',
            $root.'/config/icons/categories.yaml',
        );

        $entries = $provider->listAllExtensionEntries();
        self::assertGreaterThan(600, \count($entries));
    }
}
