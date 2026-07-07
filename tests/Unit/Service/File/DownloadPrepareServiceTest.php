<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\SharedFile;
use App\Service\Audit\DownloadDiagnosticLogger;
use App\Service\File\DownloadPrepareService;
use App\Service\File\FileEncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for prepared download job lifecycle.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class DownloadPrepareServiceTest extends TestCase
{
    private const ENCRYPTION_KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    /**
     * @brief Create a v2 encrypted shared file fixture on disk.
     * @param string $projectDir Project root.
     * @return array{sharedFile: SharedFile, storagePath: string, plainPath: string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function createEncryptedFixture(string $projectDir): array
    {
        $plainPath = tempnam(sys_get_temp_dir(), 'dps_plain_');
        self::assertIsString($plainPath);
        file_put_contents($plainPath, str_repeat('Z', 50000));

        $storageDir = $projectDir.'/var/shared/1';
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            self::fail('Cannot create storage dir');
        }
        $storagePath = $storageDir.'/fixture.bin';
        $encryption = new FileEncryptionService(self::ENCRYPTION_KEY);
        $encryption->encryptPlainFileToV2Storage($plainPath, $storagePath);

        $sharedFile = new SharedFile(1, $storagePath, 'public', 'token-fixture', 'fixture.bin', 50000);

        return [
            'sharedFile' => $sharedFile,
            'storagePath' => $storagePath,
            'plainPath' => $plainPath,
        ];
    }

    /**
     * @brief Job reaches ready state after sequential ticks.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testJobReachesReadyAfterTicks(): void
    {
        $projectDir = sys_get_temp_dir().'/dps_'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/var/download_prepare', 0775, true);
        $fixture = $this->createEncryptedFixture($projectDir);

        $service = new DownloadPrepareService(
            new FileEncryptionService(self::ENCRYPTION_KEY),
            $this->createMock(DownloadDiagnosticLogger::class),
            $projectDir,
            16384,
            3600,
            3,
            1024,
        );

        try {
            $created = $service->createAuthenticatedJob(7, $fixture['sharedFile']);
            $namespace = $service->userNamespace(7);
            $jobId = $created['job_id'];
            $complete = false;
            $guard = 0;
            while (!$complete && $guard < 200) {
                ++$guard;
                $progress = $service->tickJob($namespace, $jobId, DownloadPrepareService::ACTOR_USER, 7);
                $complete = (bool) $progress['complete'];
            }

            self::assertTrue($complete);
            $delivery = $service->resolveReadyDelivery($namespace, $jobId, DownloadPrepareService::ACTOR_USER, 7);
            self::assertSame(50000, $delivery['bytes']);
            self::assertFileExists($delivery['plain_path']);
        } finally {
            @unlink($fixture['plainPath']);
            @unlink($fixture['storagePath']);
            $this->deleteDirectory($projectDir);
        }
    }

    /**
     * @brief Recursively delete a temp project directory.
     * @param string $dir Directory path.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
