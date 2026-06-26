<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract checks for text edit action in owner vs shared dropdowns.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class TextContentEditDropdownMarkupContractTest extends TestCase
{
    /**
     * @brief Read a repository file content as string for static assertions.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Owner dropdown exposes edit action; shared-for-me dropdown does not.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testEditTextActionIsOwnerOnlyInDropdownTemplates(): void
    {
        $owned = $this->readSource('templates/files/_file_actions_dropdown.html.twig');
        self::assertStringContainsString('data-files-row-action="edit-text-open"', $owned);
        self::assertStringContainsString('fileIsPreviewText', $owned);
        self::assertStringContainsString('files.action.edit_text', $owned);
        self::assertStringContainsString('data-files-row-ext', $owned);

        $shared = $this->readSource('templates/files/_file_actions_dropdown_shared_for_me.html.twig');
        self::assertStringNotContainsString('edit-text-open', $shared);
        self::assertStringNotContainsString('files.action.edit_text', $shared);
    }
}
