<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract checks for text file editor modal template wiring.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class TextFileEditorModalTemplateContractTest extends TestCase
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
     * @brief Modal template and files index include editor assets and routes.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testTextFileEditorModalIsWiredInFilesIndex(): void
    {
        $modal = $this->readSource('templates/files/_text_file_editor_modal.html.twig');
        self::assertStringContainsString('id="textFileEditorModal"', $modal);
        self::assertStringContainsString('id="textFileEditorMount"', $modal);
        self::assertStringContainsString("path('files_preview', { id: 999999 })", $modal);
        self::assertStringContainsString("path('files_content_save', { id: 999999 })", $modal);
        self::assertStringContainsString('csrfContentEdit', $modal);
        self::assertStringContainsString('textFileEditorTabPreview', $modal);
        self::assertStringContainsString('files.text_editor.tab_preview', $modal);

        $index = $this->readSource('templates/files/index.html.twig');
        self::assertStringContainsString('_text_file_editor_modal.html.twig', $index);
        self::assertStringContainsString('text-file-editor.css', $index);
        self::assertStringContainsString('text-file-editor.js', $index);
        self::assertStringContainsString('marked@', $index);
    }
}
