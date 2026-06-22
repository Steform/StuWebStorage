<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static JS sentinels for global create-folder action wiring.
 */
final class GlobalCreateFolderActionJsTest extends TestCase
{
    /**
     * @brief Read a repository file and return raw source.
     * @param string $relativePath Repo-relative file path.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Global actions JS must handle create-folder without requiring selected file ids.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testFilesSpaceHandlesCreateFolderGlobalAction(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("action === 'create-folder'", $source);
        self::assertStringContainsString("document.getElementById('filesCreateFolderModal')", $source);
        self::assertStringContainsString("data-files-action-requires-selection", $source);
    }

    /**
     * @brief Folder navigation must be driven by simple click only with guard clauses.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testFilesSpaceUsesSimpleClickForFolderOpenWithGuardClauses(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("closest('[data-files-folder-open-url]')", $source);
        self::assertStringContainsString('shouldIgnoreRowActionTarget(event.target)', $source);
        self::assertStringContainsString('event.preventDefault();', $source);
        self::assertStringContainsString('window.location.href = url;', $source);
        self::assertStringNotContainsString("document.addEventListener('dblclick'", $source);
        self::assertStringNotContainsString("window.matchMedia('(pointer: coarse)').matches", $source);
    }

    /**
     * @brief Row action menu must open from contextmenu plus touch long-press, while keeping keyboard support.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testFilesSpaceUsesContextmenuAndTouchLongPressForRowActions(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("document.addEventListener('contextmenu'", $source);
        self::assertStringContainsString("closest('[data-files-row-target]')", $source);
        self::assertStringContainsString('openRowContextMenuForCell', $source);
        self::assertStringContainsString("document.addEventListener('pointerdown'", $source);
        self::assertStringContainsString("event.pointerType !== 'touch'", $source);
        self::assertStringContainsString('LONG_PRESS_MS = 500', $source);
        self::assertStringContainsString('handleRowTargetKeyboardOpen', $source);
        self::assertStringNotContainsString("document.addEventListener('click', function (event) {\n        var cell = event.target && event.target.closest", $source);
    }

    /**
     * @brief Folder actions JS must support folder properties and individual folder share handlers.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesSpaceHandlesFolderActionDropdownHooks(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('filesFolderPropertiesModal', $source);
        self::assertStringContainsString("closest('[data-files-folder-action]')", $source);
        self::assertStringContainsString("action === 'properties'", $source);
        self::assertStringContainsString("action === 'share-public'", $source);
        self::assertStringContainsString("action === 'share-friends'", $source);
        self::assertStringContainsString('function closeAllActionMenus()', $source);
        self::assertStringContainsString('closeAllActionMenus();', $source);
        self::assertStringContainsString("openPublicShareModal('folder', folderId, null, getSubjectUserIdFromTrigger(trigger))", $source);
        self::assertStringContainsString("openFriendsShareModal('folder', folderId, null, getSubjectUserIdFromTrigger(trigger))", $source);
        self::assertStringNotContainsString("openPublicShareModal('bulk'", $source);
        self::assertStringNotContainsString("openFriendsShareModal('bulk'", $source);
    }
}
