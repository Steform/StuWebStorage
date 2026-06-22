<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for row action menu closure before row action dispatch.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class RowActionContextMenuClosureContractTest extends TestCase
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
     * @brief Row actions must invoke centralized menu closure before modal/copy flows.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testRowActionListenerUsesCentralizedMenuClosure(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("closest('[data-files-row-action]')", $source);
        self::assertStringContainsString('function closeAllActionMenus()', $source);
        self::assertStringContainsString('if (anyRowAction || anyFolderCopyPwdAction) {', $source);
        self::assertStringContainsString('closeAllActionMenus();', $source);
        self::assertStringContainsString('[data-files-row-action="delete-open"]', $source);
        self::assertStringContainsString('[data-files-row-action="rename-open"]', $source);
        self::assertStringContainsString('[data-files-row-action="share-public"]', $source);
        self::assertStringContainsString('[data-files-row-action="share-friends"]', $source);
        self::assertStringContainsString('[data-files-row-action="copy-public-link"]', $source);
        self::assertStringContainsString('[data-files-row-action="copy-public-link-with-password"]', $source);
    }
}
