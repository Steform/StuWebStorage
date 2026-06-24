<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\File\FileExtensionIconResolver;
use App\File\FileIconMappingProvider;
use PHPUnit\Framework\TestCase;

/**
 * @brief Contract between resolver icon list and committed import manifest.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileTypeIconImportContractTest extends TestCase
{
    /**
     * @brief Committed icon manifest matches resolver export output.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testCommittedIconManifestMatchesResolver(): void
    {
        $root = dirname(__DIR__, 3);
        $manifestPath = $root.DIRECTORY_SEPARATOR.'config/icons/vscode-icons-used.txt';
        self::assertFileExists($manifestPath);

        $manifest = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            file($manifestPath, FILE_IGNORE_NEW_LINES) ?: [],
        )));

        $resolver = new FileExtensionIconResolver(
            new FileIconMappingProvider(
                $root.'/config/icons/mappings',
                $root.'/config/icons/categories.yaml',
            ),
        );
        $expected = $resolver->listUsedIconSuffixes();

        sort($manifest);
        sort($expected);

        self::assertSame($expected, $manifest);
    }
}
