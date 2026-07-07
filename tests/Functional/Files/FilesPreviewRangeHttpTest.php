<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\Service\File\FileEncryptionService;
use App\Service\Http\HttpByteRange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Contract and integration checks for HTTP Range preview streaming.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class FilesPreviewRangeHttpTest extends TestCase
{
    private const ENCRYPTION_KEY = 'rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr';

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
     * @brief Preview controller wires range parsing, partial responses, and conditional audit.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testPreviewControllerRangeContract(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('encryptedStreamDeliveryService->buildEncryptedStreamResponse', $source);
        self::assertStringContainsString('if (!$hasRangeHeader)', $source);
    }

    /**
     * @brief Range header mapped to partial decrypt yields bytes matching a simulated 206 body.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testRangeHeaderMapsToPartialDecryptPayload(): void
    {
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);
        $plain = tempnam(sys_get_temp_dir(), 'pr_plain_');
        $enc = tempnam(sys_get_temp_dir(), 'pr_enc_');
        self::assertIsString($plain);
        self::assertIsString($enc);

        $payload = str_repeat('Z', 9000);
        file_put_contents($plain, $payload);
        $svc->encryptPlainFileToV2Storage($plain, $enc);

        try {
            $request = Request::create('/files/preview/1', 'GET');
            $request->headers->set('Range', 'bytes=100-199');
            $range = HttpByteRange::tryFromRequest($request, strlen($payload));
            self::assertNotNull($range);
            self::assertSame(100, $range->getLength());

            $tmp = fopen('php://temp', 'rb+');
            self::assertIsResource($tmp);
            $svc->streamDecryptStorageRangeToHandle($enc, $range->getStart(), $range->getLength(), $tmp);
            rewind($tmp);
            $body = stream_get_contents($tmp);
            fclose($tmp);

            self::assertSame(substr($payload, 100, 100), $body);
            self::assertSame('bytes 100-199/9000', $range->contentRangeHeader());
        } finally {
            @unlink($plain);
            @unlink($enc);
        }
    }
}
