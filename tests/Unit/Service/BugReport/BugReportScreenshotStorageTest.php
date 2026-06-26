<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\BugReport;

use App\Entity\BugReport;
use App\Entity\User;
use App\Service\BugReport\BugReportScreenshotStorage;
use PHPUnit\Framework\TestCase;

class BugReportScreenshotStorageTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/bug-report-storage-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/var/bug-reports', 0775, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->projectDir.'/var/bug-reports/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->projectDir.'/var/bug-reports');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    /**
     * @brief Ensure valid JPEG payload is persisted and metadata attached.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSaveFromBase64PersistsJpegScreenshot(): void
    {
        $report = $this->createBugReportWithId(7);
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $payload = 'data:image/jpeg;base64,'.base64_encode($jpeg);
        $storage = new BugReportScreenshotStorage($this->projectDir);

        $saved = $storage->saveFromBase64($report, $payload);

        self::assertTrue($saved);
        self::assertSame('var/bug-reports/7.jpg', $report->getScreenshotPath());
        self::assertSame('image/jpeg', $report->getScreenshotMime());
        self::assertFileExists($this->projectDir.'/var/bug-reports/7.jpg');
    }

    /**
     * @brief Ensure invalid payload is rejected without metadata changes.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSaveFromBase64RejectsInvalidPayload(): void
    {
        $report = $this->createBugReportWithId(8);
        $storage = new BugReportScreenshotStorage($this->projectDir);

        $saved = $storage->saveFromBase64($report, 'not-valid');

        self::assertFalse($saved);
        self::assertNull($report->getScreenshotPath());
    }

    /**
     * @brief Build bug report aggregate with test identifier.
     * @param int $id Bug report identifier.
     * @return BugReport
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function createBugReportWithId(int $id): BugReport
    {
        $user = (new User())->setEmail('user@example.com')->setPseudonym('user')->setRoles(['ROLE_USER']);
        $report = new BugReport($user, 'Action', 'Observed', null, 'files_index', '/files', null, 'fr', 'light', null, null, null, null, null, null, null);
        $reflection = new \ReflectionClass($report);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($report, $id);

        return $report;
    }
}
