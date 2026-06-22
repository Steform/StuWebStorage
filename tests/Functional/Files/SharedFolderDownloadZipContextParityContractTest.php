<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for context parity between shared folder properties and zip download endpoints.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class SharedFolderDownloadZipContextParityContractTest extends TestCase
{
    /**
     * @brief Read repository source file content.
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
     * @brief Shared-folder download zip endpoint must reuse effective grantee resolver and access helper.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testSharedFolderDownloadZipUsesSameEffectiveGranteeResolution(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("name: 'files_shared_folder_download_zip'", $source);
        self::assertStringContainsString('public function sharedFolderDownloadZip(Request $request, int $id): Response', $source);
        self::assertStringContainsString('tryResolveEffectiveGranteeIdForAdminSubject($request, true)', $source);
        self::assertStringContainsString('resolveSharedFolderAccess($id, $effectiveGranteeId)', $source);
        self::assertStringContainsString('throw $this->createNotFoundException();', $source);
    }
}
