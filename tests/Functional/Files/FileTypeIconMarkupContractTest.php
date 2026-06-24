<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract tests for UX file type icons in listing templates.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileTypeIconMarkupContractTest extends TestCase
{
    /**
     * @brief Read repository template source.
     *
     * @param string $relativePath Template path relative to project root.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function readTemplate(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Listing templates use shared UX icon partial instead of legacy SVG assets.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testListingTemplatesUseFileTypeIconPartial(): void
    {
        $templates = [
            'templates/files/_listing_fragment.html.twig',
            'templates/files/components/_user_files_pane_owned_table.html.twig',
            'templates/files/components/_user_files_pane_owned_grid.html.twig',
            'templates/files/components/_user_files_pane_shared_table.html.twig',
            'templates/files/components/_user_files_pane_shared_grid.html.twig',
            'templates/public_file/landing.html.twig',
        ];

        foreach ($templates as $template) {
            $source = $this->readTemplate($template);
            self::assertStringContainsString(
                "include 'components/_file_type_icon.html.twig'",
                $source,
                $template,
            );
            self::assertStringNotContainsString('images/files/pdf.svg', $source, $template);
            self::assertStringNotContainsString('images/files/file.svg', $source, $template);
            self::assertStringNotContainsString('bi bi-film', $source, $template);
        }
    }

    /**
     * @brief Shared partial declares UX icon size modifiers.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testFileTypeIconPartialUsesUxRenderer(): void
    {
        $source = $this->readTemplate('templates/components/_file_type_icon.html.twig');

        self::assertStringContainsString('file_icon_descriptor', $source);
        self::assertStringContainsString('file_ux_icon', $source);
        self::assertStringContainsString('filename', $source);
        self::assertStringContainsString('files-type-icon--', $source);
    }
}
