<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\EncryptedStreamDeliveryService;
use App\Service\File\FileEncryptionService;
use App\Service\File\V2SegmentIndexService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Unit tests for encrypted stream delivery responses.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class EncryptedStreamDeliveryServiceTest extends TestCase
{
    private const ENCRYPTION_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @brief Build encrypted v2 fixture and return storage path.
     * @param string $projectDir Project directory.
     * @param string $plain Plaintext payload.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function createV2Fixture(string $projectDir, string $plain): string
    {
        $plainPath = $projectDir.'/plain.bin';
        $storagePath = $projectDir.'/storage.cvf2';
        file_put_contents($plainPath, $plain);
        $encryption = new FileEncryptionService(self::ENCRYPTION_KEY);
        $encryption->encryptPlainFileToV2Storage($plainPath, $storagePath);

        return $storagePath;
    }

    /**
     * @brief Full download returns 200 with Content-Length.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testBuildFullStreamResponse(): void
    {
        $projectDir = sys_get_temp_dir().'/enc-stream-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0775, true);
        $plain = str_repeat('A', 9000);
        $storagePath = $this->createV2Fixture($projectDir, $plain);

        $service = new EncryptedStreamDeliveryService(new FileEncryptionService(self::ENCRYPTION_KEY), new V2SegmentIndexService(new FileEncryptionService(self::ENCRYPTION_KEY)));
        $request = Request::create('/download', 'GET');
        $response = $service->buildEncryptedStreamResponse(
            $request,
            $storagePath,
            strlen($plain),
            'application/octet-stream',
            EncryptedStreamDeliveryService::DISPOSITION_ATTACHMENT,
            'sample.bin',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame((string) strlen($plain), $response->headers->get('Content-Length'));
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    /**
     * @brief Range request returns 206 with Content-Range.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testBuildPartialStreamResponse(): void
    {
        $projectDir = sys_get_temp_dir().'/enc-stream-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0775, true);
        $plain = str_repeat('B', 12000);
        $storagePath = $this->createV2Fixture($projectDir, $plain);

        $service = new EncryptedStreamDeliveryService(new FileEncryptionService(self::ENCRYPTION_KEY), new V2SegmentIndexService(new FileEncryptionService(self::ENCRYPTION_KEY)));
        $request = Request::create('/download', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=100-199']);
        $response = $service->buildEncryptedStreamResponse(
            $request,
            $storagePath,
            strlen($plain),
            'application/octet-stream',
            EncryptedStreamDeliveryService::DISPOSITION_ATTACHMENT,
            'sample.bin',
        );

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 100-199/12000', $response->headers->get('Content-Range'));
        self::assertSame('100', $response->headers->get('Content-Length'));
    }

    /**
     * @brief HEAD returns headers without requiring stream body.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testHeadResponse(): void
    {
        $projectDir = sys_get_temp_dir().'/enc-stream-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0775, true);
        $plain = 'hello';
        $storagePath = $this->createV2Fixture($projectDir, $plain);

        $service = new EncryptedStreamDeliveryService(new FileEncryptionService(self::ENCRYPTION_KEY));
        $request = Request::create('/download', 'HEAD');
        $response = $service->buildEncryptedStreamResponse(
            $request,
            $storagePath,
            strlen($plain),
            'application/octet-stream',
            EncryptedStreamDeliveryService::DISPOSITION_ATTACHMENT,
            'sample.bin',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }
}
