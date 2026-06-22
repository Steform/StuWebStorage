<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for selection zip archive path structure and collision safeguards.
 * @date 2026-05-07
 * @author Stephane H.
 */
final class DownloadSelectionZipContractTest extends TestCase
{
    /**
     * @brief Read repository file contents as a raw string.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Selection zip flow must build relative paths from selected folder roots.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testSelectionZipBuildsRelativePathsFromFolderRoots(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('selectedFolderRoots', $controller);
        self::assertStringContainsString('selectedFolderId', $controller);
        self::assertStringContainsString('buildRelativeFolderPathFromRoot', $controller);
        self::assertStringContainsString("ltrim(\$relativePrefix.'/'", $controller);
        self::assertStringContainsString('tryResolveEffectiveOwnerIdForAdminSubject($request, true)', $controller);
        self::assertStringContainsString('files.flash.godview_subject_invalid', $controller);
        self::assertStringContainsString('$effectiveOwnerId', $controller);
    }

    /**
     * @brief Selection zip flow must sanitize and de-duplicate final entry paths deterministically.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testSelectionZipSanitizesAndDeduplicatesEntryPaths(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('buildUniqueZipEntryName', $controller);
        self::assertStringContainsString('appendZipSuffixToEntryName', $controller);
        self::assertStringContainsString('ZipEntryNameSanitizer::sanitizeEntryPath', $controller);
        self::assertStringContainsString('while (isset($usedEntryNames[$candidateEntryName]))', $controller);
    }

    /**
     * @brief Folder zip service must expose reusable relative path builder for archive path parity.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testFolderZipServiceExposesRelativePathBuilderForReuse(): void
    {
        $service = $this->readSource('src/Service/Share/FolderZipService.php');

        self::assertStringContainsString('public function buildRelativeFolderPathFromRoot', $service);
        self::assertStringContainsString('return $this->buildRelativeFolderPath($root, $child);', $service);
    }
}

