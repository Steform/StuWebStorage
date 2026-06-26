<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract checks for text preview triggers (Twig + JS) aligned with FilesController text allowlist.
 * @date 2026-05-06
 * @author Stephane H.
 */
final class FilesPreviewTextTriggersMarkupContractTest extends TestCase
{
    /**
     * @brief Read a repository file content as string for static assertions.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Listing and dropdown templates expose text preview triggers with files_preview route.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testTwigTemplatesExposeTextPreviewTriggers(): void
    {
        $listing = $this->readSource('templates/files/_listing_fragment.html.twig');
        self::assertStringContainsString("data-media-preview-type=\"text\"", $listing);
        self::assertStringContainsString("path('files_preview', { id: file.id })", $listing);
        self::assertStringContainsString("'txt', 'log', 'md', 'markdown', 'json', 'csv', 'tsv', 'xml', 'yml', 'yaml', 'ini', 'conf'", $listing);

        $dropdown = $this->readSource('templates/files/_file_actions_dropdown.html.twig');
        self::assertStringContainsString('fileIsPreviewText', $dropdown);
        self::assertStringContainsString('{% elseif fileIsPreviewText %}text', $dropdown);
        self::assertStringContainsString('data-files-row-action="edit-text-open"', $dropdown);
        self::assertStringContainsString('files.action.edit_text', $dropdown);
        self::assertStringContainsString('_media_preview_owned_text_attrs.html.twig', $dropdown);

        $ownedTextAttrs = $this->readSource('templates/files/_media_preview_owned_text_attrs.html.twig');
        self::assertStringContainsString('data-media-preview-text-editable="1"', $ownedTextAttrs);
        self::assertStringContainsString('data-files-row-id', $ownedTextAttrs);

        $dropdownShared = $this->readSource('templates/files/_file_actions_dropdown_shared_for_me.html.twig');
        self::assertStringContainsString('fileIsPreviewTextShared', $dropdownShared);
        self::assertStringContainsString('{% elseif fileIsPreviewTextShared %}text', $dropdownShared);
        self::assertStringNotContainsString('edit-text-open', $dropdownShared);
        self::assertStringNotContainsString('data-media-preview-text-editable', $dropdownShared);

        $paneOwned = $this->readSource('templates/files/components/_user_files_pane_owned_table.html.twig');
        self::assertStringContainsString("data-media-preview-type=\"text\"", $paneOwned);

        $paneShared = $this->readSource('templates/files/components/_user_files_pane_shared_table.html.twig');
        self::assertStringContainsString('data-media-preview-type="text"', $paneShared);

        $paneOwnedGrid = $this->readSource('templates/files/components/_user_files_pane_owned_grid.html.twig');
        self::assertStringContainsString(": 'text'))", $paneOwnedGrid);

        $paneSharedGrid = $this->readSource('templates/files/components/_user_files_pane_shared_grid.html.twig');
        self::assertStringContainsString(": 'text'))", $paneSharedGrid);
    }

    /**
     * @brief media-preview.js supports text type with fetch and textContent (no innerHTML).
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testMediaPreviewJsSupportsTextFetchContract(): void
    {
        $js = $this->readSource('public/js/media-preview.js');
        self::assertStringContainsString("&& type !== 'text')", $js);
        self::assertStringContainsString("type === 'text'", $js);
        self::assertStringContainsString("setSlotVisible('text'", $js);
        self::assertStringContainsString("typeAttr === 'text'", $js);
        self::assertStringContainsString('credentials: \'same-origin\'', $js);
        self::assertStringContainsString('preEl.textContent', $js);
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringContainsString('data-media-preview-text-editable', $js);
        self::assertStringContainsString('TextFileEditor.open', $js);
        self::assertStringContainsString('mediaPreviewTextEditBtn', $js);
    }
}
