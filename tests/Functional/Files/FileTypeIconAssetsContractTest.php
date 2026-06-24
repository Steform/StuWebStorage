<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract between committed icon manifest and local SVG assets.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileTypeIconAssetsContractTest extends TestCase
{
    private const LOCAL_ICON_SET = 'vscode';

    /**
     * @brief Every manifest entry has a matching local SVG under assets/icons/vscode/.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testLocalIconAssetsExistForManifest(): void
    {
        $root = dirname(__DIR__, 3);
        $manifestPath = $root.DIRECTORY_SEPARATOR.'config/icons/vscode-icons-used.txt';
        self::assertFileExists($manifestPath);

        $suffixes = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            file($manifestPath, FILE_IGNORE_NEW_LINES) ?: [],
        )));

        self::assertNotEmpty($suffixes, 'Icon manifest must list at least one suffix.');

        $missing = [];
        foreach ($suffixes as $suffix) {
            $path = sprintf(
                '%s/assets/icons/%s/%s.svg',
                $root,
                self::LOCAL_ICON_SET,
                $suffix,
            );
            if (!is_readable($path)) {
                $missing[] = $suffix;
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                "Missing local icons (run composer import-icons): %s",
                implode(', ', $missing),
            ),
        );
    }
}
