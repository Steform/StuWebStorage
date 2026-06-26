<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract checks for text file editor JavaScript.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class TextFileEditorJsContractTest extends TestCase
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
     * @brief Editor JS loads preview, saves content, and supports markdown preview.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testTextFileEditorJsContract(): void
    {
        $editorJs = $this->readSource('public/js/text-file-editor.js');
        self::assertStringContainsString('window.TextFileEditor', $editorJs);
        self::assertStringContainsString('codemirror@6.0.1', $editorJs);
        self::assertStringContainsString('credentials: \'same-origin\'', $editorJs);
        self::assertStringContainsString('X-CSRF-Token', $editorJs);
        self::assertStringContainsString('text/plain; charset=utf-8', $editorJs);
        self::assertStringContainsString('marked.parse', $editorJs);
        self::assertStringContainsString('files:text-content-saved', $editorJs);

        $filesSpaceJs = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('data-files-row-action="edit-text-open"', $filesSpaceJs);
        self::assertStringContainsString('TextFileEditor.open', $filesSpaceJs);
        self::assertStringContainsString('files:text-content-saved', $filesSpaceJs);
    }
}
