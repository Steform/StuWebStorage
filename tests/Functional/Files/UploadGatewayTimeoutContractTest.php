<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contracts: upload requests stay short enough to avoid HTTP 504 gateway timeouts.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class UploadGatewayTimeoutContractTest extends TestCase
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
     * @brief Chunk/tick budgets and retries are wired to survive slow uplinks / proxy timeouts.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testUploadBudgetsAndRetriesAvoidGatewayTimeout(): void
    {
        $services = $this->readSource('config/services.yaml');
        self::assertStringContainsString('app.upload_chunk_bytes: 2097152', $services);

        $job = $this->readSource('src/Service/File/UploadEncryptJobService.php');
        self::assertStringContainsString('TICK_PLAIN_BUDGET = 8388608', $job);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('createFromUploadSession($ownerId, $uploadId, $maxUploadBytes)', $controller);
        self::assertStringNotContainsString(
            'uploadEncryptJobService->tick($ownerId, (string) $task[\'taskId\'])',
            $controller
        );

        $chunked = $this->readSource('src/Service/File/ChunkedUploadService.php');
        self::assertStringContainsString('resolveAdvertisedChunkBytes', $chunked);
        self::assertStringContainsString('if ($chunkIndex < $expectedIndex)', $chunked);

        $space = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('CHUNK_MAX_ATTEMPTS', $space);
        self::assertStringContainsString('isUploadTransientStatus', $space);
        self::assertStringContainsString('msgXhrGatewayTimeout', $space);

        $encryptJs = $this->readSource('public/js/files-upload-encrypt.js');
        self::assertStringContainsString('TICK_MAX_ATTEMPTS', $encryptJs);
        self::assertStringContainsString('isTransientGatewayStatus', $encryptJs);

        $httpError = $this->readSource('public/js/files-http-error.js');
        self::assertStringContainsString('gatewayTimeout', $httpError);
        self::assertStringContainsString('isTransientGatewayStatus', $httpError);
    }
}
