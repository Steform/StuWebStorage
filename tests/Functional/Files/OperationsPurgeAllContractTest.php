<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contracts for OPS purge-all (stop and delete non-done operations).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class OperationsPurgeAllContractTest extends TestCase
{
    /**
     * @brief Read repository source.
     * @param string $relativePath Relative path.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Purge route, services, UI confirm, and client abort wiring exist.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testPurgeAllWiringExists(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/src/Service/File/OperationsPurgeService.php');
        self::assertFileExists($root.'/templates/operations/_ops_purge_confirm_modal.html.twig');

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString("#[Route('/files/operations/purge'", $controller);
        self::assertStringContainsString('files_operations_purge', $controller);
        self::assertStringContainsString('operationsPurgeService->purgeNonDone', $controller);

        $purge = $this->readSource('src/Service/File/OperationsPurgeService.php');
        self::assertStringContainsString('purgeNonDoneForOwner', $purge);
        self::assertStringContainsString('abortAllSessionsForOwner', $purge);
        self::assertStringContainsString('upload.ops.purge', $purge);

        $encrypt = $this->readSource('src/Service/File/UploadEncryptJobService.php');
        self::assertStringContainsString('function purgeNonDoneForOwner', $encrypt);
        self::assertStringContainsString('STATUS_DONE', $encrypt);

        $extract = $this->readSource('src/Service/File/ZipExtractService.php');
        self::assertStringContainsString('function purgeNonDoneForOwner', $extract);

        $chunked = $this->readSource('src/Service/File/ChunkedUploadService.php');
        self::assertStringContainsString('function abortAllSessionsForOwner', $chunked);

        $nav = $this->readSource('templates/components/_operations_center_nav.html.twig');
        self::assertStringContainsString('data-ops-purge-url', $nav);
        self::assertStringContainsString('data-msg-purge-all', $nav);

        $centerJs = $this->readSource('public/js/files-operations-center.js');
        self::assertStringContainsString('data-ops-purge-all', $centerJs);
        self::assertStringContainsString('runPurgeAll', $centerJs);
        self::assertStringContainsString('opsPurgeConfirmModal', $centerJs);
        self::assertStringNotContainsString('window.confirm(', $centerJs);

        $opsJs = $this->readSource('public/js/files-operations.js');
        self::assertStringContainsString('abortAllLocal', $opsJs);

        $confirm = $this->readSource('templates/operations/_ops_purge_confirm_modal.html.twig');
        self::assertStringContainsString('data-ops-purge-confirm', $confirm);
        self::assertStringContainsString('opsPurgeConfirmModal', $confirm);

        $base = $this->readSource('templates/base.html.twig');
        self::assertStringContainsString('_ops_purge_confirm_modal.html.twig', $base);

        $fr = $this->readSource('translations/messages.fr.yaml');
        self::assertStringContainsString('purge_all:', $fr);
        self::assertStringContainsString('purge_all_confirm_body:', $fr);

        $en = $this->readSource('translations/messages.en.yaml');
        self::assertStringContainsString('purge_all:', $en);

        $rule = $this->readSource('.cursor/rules/operations-center.mdc');
        self::assertStringContainsString('Purge all', $rule);
        self::assertStringContainsString('files/operations/purge', $rule);
    }
}
