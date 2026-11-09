<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contracts: permission failures must not be shown as “server unreachable”.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class JsonApiPermissionErrorContractTest extends TestCase
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
     * @brief Server maps permission errors to a dedicated flash key and JSON message.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testPermissionErrorsAreMappedToDedicatedFlashAndJson(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/src/EventSubscriber/JsonApiExceptionSubscriber.php');
        self::assertFileExists($root.'/src/Service/Infrastructure/RuntimePermissionFailure.php');
        self::assertFileExists($root.'/public/js/files-http-error.js');

        $subscriber = $this->readSource('src/EventSubscriber/JsonApiExceptionSubscriber.php');
        self::assertStringContainsString('KernelEvents::EXCEPTION', $subscriber);
        self::assertStringContainsString('RuntimePermissionFailure::FLASH_KEY', $subscriber);
        self::assertStringContainsString('files.upload.xhr_server_error', $subscriber);
        self::assertStringContainsString('wantsJson', $subscriber);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('RuntimePermissionFailure::FLASH_KEY', $controller);
        self::assertStringContainsString('jsonApiError', $controller);

        $encryptJob = $this->readSource('src/Service/File/UploadEncryptJobService.php');
        self::assertStringContainsString('RuntimePermissionFailure::ensureWritableDirectory', $encryptJob);
        self::assertStringContainsString('RuntimePermissionFailure::FLASH_KEY', $encryptJob);

        $js = $this->readSource('public/js/files-http-error.js');
        self::assertStringContainsString('permission denied', $js);
        self::assertStringContainsString('function diagnose', $js);

        $space = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('diagnoseUploadHttpError', $space);
        self::assertStringContainsString('FilesHttpError.diagnose', $space);

        $encryptJs = $this->readSource('public/js/files-upload-encrypt.js');
        self::assertStringContainsString('diagnosePack', $encryptJs);

        $index = $this->readSource('templates/files/index.html.twig');
        self::assertStringContainsString('data-msg-xhr-permission-error', $index);
        self::assertStringContainsString('data-msg-xhr-server-error', $index);
        self::assertStringContainsString('data-msg-xhr-gateway-timeout', $index);

        $fr = $this->readSource('translations/messages.fr.yaml');
        self::assertStringContainsString('runtime_permission_denied:', $fr);
        self::assertStringContainsString("xhr_server_error: 'Erreur serveur (HTTP %status%).'", $fr);
        self::assertStringContainsString('xhr_gateway_timeout:', $fr);
        self::assertStringContainsString('fix-runtime-permissions.sh', $fr);

        $en = $this->readSource('translations/messages.en.yaml');
        self::assertStringContainsString('runtime_permission_denied:', $en);
        self::assertStringContainsString("xhr_server_error: 'Server error (HTTP %status%).'", $en);
        self::assertStringContainsString('xhr_gateway_timeout:', $en);
    }
}
