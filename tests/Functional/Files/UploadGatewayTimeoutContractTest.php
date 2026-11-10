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
     * @brief Fast default chunks with one-shot fallback restart after gateway timeout (EPIC-UP-504b).
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testUploadBudgetsAndRetriesAvoidGatewayTimeout(): void
    {
        $services = $this->readSource('config/services.yaml');
        self::assertStringContainsString('app.upload_chunk_bytes: 2097152', $services);
        self::assertStringContainsString('app.upload_chunk_bytes_fallback: 1048576', $services);
        self::assertStringContainsString('app.upload_chunk_bytes_min: 262144', $services);
        self::assertStringContainsString('app.upload_chunk_time_budget_ms: 25000', $services);

        $job = $this->readSource('src/Service/File/UploadEncryptJobService.php');
        self::assertStringContainsString('TICK_PLAIN_BUDGET = 8388608', $job);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('createFromUploadSession($ownerId, $uploadId, $maxUploadBytes)', $controller);
        self::assertStringNotContainsString(
            'uploadEncryptJobService->tick($ownerId, (string) $task[\'taskId\'])',
            $controller
        );
        self::assertStringContainsString('files_upload_chunk_client_event', $controller);
        self::assertStringContainsString('reportClientGatewayTimeout', $controller);
        self::assertStringContainsString("\$request->request->get('fallback'", $controller);
        self::assertStringContainsString("\$request->request->get('previous_upload_id'", $controller);

        $chunked = $this->readSource('src/Service/File/ChunkedUploadService.php');
        self::assertStringContainsString('resolveAdvertisedChunkBytes', $chunked);
        self::assertStringContainsString('resolveMinChunkBytes', $chunked);
        self::assertStringContainsString('chunk_size_min_bytes', $chunked);
        self::assertStringContainsString('chunk_time_budget_ms', $chunked);
        self::assertStringContainsString('chunkBytesFallback', $chunked);
        self::assertStringContainsString("'fallback' => \$fallback", $chunked);
        self::assertStringContainsString('if ($chunkIndex < $expectedIndex)', $chunked);
        self::assertStringContainsString('upload.chunk.gateway_timeout', $chunked);

        $space = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('CHUNK_MAX_ATTEMPTS', $space);
        self::assertStringContainsString('isUploadTransientStatus', $space);
        self::assertStringContainsString('msgXhrGatewayTimeout', $space);
        self::assertStringContainsString('__upload_gateway__', $space);
        self::assertStringContainsString('onFallbackRestart', $space);
        self::assertStringContainsString("fallback: true", $space);
        self::assertStringContainsString('previousUploadId', $space);
        self::assertStringContainsString('reportGatewayEvent', $space);
        self::assertStringContainsString('shrinkChunkSize', $space);
        self::assertStringContainsString('useFallback', $space);

        $encryptJs = $this->readSource('public/js/files-upload-encrypt.js');
        self::assertStringContainsString('TICK_MAX_ATTEMPTS', $encryptJs);
        self::assertStringContainsString('isTransientGatewayStatus', $encryptJs);

        $httpError = $this->readSource('public/js/files-http-error.js');
        self::assertStringContainsString('gatewayTimeout', $httpError);
        self::assertStringContainsString('isTransientGatewayStatus', $httpError);

        $index = $this->readSource('templates/files/index.html.twig');
        self::assertStringContainsString('data-files-upload-client-event-url', $index);
        self::assertStringContainsString('data-msg-chunk-retrying', $index);
        self::assertStringContainsString('data-msg-chunk-fallback-restart', $index);

        $fr = $this->readSource('translations/messages.fr.yaml');
        $en = $this->readSource('translations/messages.en.yaml');
        self::assertStringContainsString('chunk_retrying:', $fr);
        self::assertStringContainsString('chunk_fallback_restart:', $fr);
        self::assertStringContainsString('chunk_retrying:', $en);
        self::assertStringContainsString('chunk_fallback_restart:', $en);
    }
}
