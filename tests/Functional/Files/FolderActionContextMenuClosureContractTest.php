<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for folder action menu closure before folder action dispatch.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class FolderActionContextMenuClosureContractTest extends TestCase
{
    /**
     * @brief Read repository source file.
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Folder actions must rely on centralized menu closure helper.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFolderActionListenerUsesCentralizedMenuClosure(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("closest('[data-files-folder-action]')", $source);
        self::assertStringContainsString('var action = trigger.getAttribute(\'data-files-folder-action\') || \'\';', $source);
        self::assertStringContainsString('closeAllActionMenus();', $source);
        self::assertStringContainsString("action === 'properties'", $source);
        self::assertStringContainsString("action === 'share-public'", $source);
        self::assertStringContainsString("action === 'share-friends'", $source);
        self::assertStringContainsString("action === 'rename-open'", $source);
        self::assertStringContainsString("action === 'resolve-copy-public-link'", $source);
    }
}
