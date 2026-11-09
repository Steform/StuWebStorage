<?php

declare(strict_types=1);

namespace App\Service\BugReport;

use App\Entity\BugReport;

/**
 * @brief Persist optional bug report screenshots on local disk.
 * @author Stephane H.
 * @date 2026-06-26
 */
final class BugReportScreenshotStorage
{
    private const RELATIVE_DIR = 'var/bug-reports';
    private const MAX_BYTES = 512000;

    /**
     * @brief Build screenshot storage service.
     * @param string $projectDir Application project directory.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function __construct(
        private readonly string $projectDir
    ) {
    }

    /**
     * @brief Decode and persist screenshot payload for one bug report.
     * @param BugReport $bugReport Bug report aggregate with persisted identifier.
     * @param string $base64Payload Base64 or data URL screenshot payload.
     * @return bool True when screenshot metadata was attached.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function saveFromBase64(BugReport $bugReport, string $base64Payload): bool
    {
        $reportId = $bugReport->getId();
        if ($reportId === null || $reportId < 1) {
            return false;
        }

        $decoded = $this->decodePayload($base64Payload);
        if ($decoded === null) {
            return false;
        }

        [$binary, $mime] = $decoded;
        if (strlen($binary) < 1 || strlen($binary) > self::MAX_BYTES) {
            return false;
        }

        $extension = $mime === 'image/png' ? 'png' : 'jpg';
        $relativePath = self::RELATIVE_DIR.'/'.$reportId.'.'.$extension;
        $absoluteDir = $this->projectDir.'/'.self::RELATIVE_DIR;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return false;
        }

        $absolutePath = $this->projectDir.'/'.$relativePath;
        if (file_put_contents($absolutePath, $binary) === false) {
            return false;
        }

        $bugReport->attachScreenshot($relativePath, $mime, strlen($binary));

        return true;
    }

    /**
     * @brief Resolve absolute screenshot path when file exists.
     * @param BugReport $bugReport Bug report aggregate.
     * @return string|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getAbsolutePath(BugReport $bugReport): ?string
    {
        $relativePath = $bugReport->getScreenshotPath();
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }

        $absolutePath = $this->projectDir.'/'.$relativePath;
        if (!is_readable($absolutePath)) {
            return null;
        }

        return $absolutePath;
    }

    /**
     * @brief Decode supported screenshot payload formats.
     * @param string $base64Payload Raw screenshot payload.
     * @return array{0: string, 1: string}|null Binary content and MIME type.
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function decodePayload(string $base64Payload): ?array
    {
        $payload = trim($base64Payload);
        if ($payload === '') {
            return null;
        }

        $mime = 'image/jpeg';
        if (str_starts_with($payload, 'data:')) {
            if (preg_match('#^data:(image/(?:png|jpeg));base64,(.+)$#i', $payload, $matches) !== 1) {
                return null;
            }
            $mime = strtolower($matches[1]) === 'image/png' ? 'image/png' : 'image/jpeg';
            $payload = $matches[2];
        }

        $binary = base64_decode($payload, true);
        if ($binary === false) {
            return null;
        }

        $detectedMime = $this->detectMime($binary);
        if ($detectedMime === null) {
            return null;
        }

        return [$binary, $detectedMime];
    }

    /**
     * @brief Detect supported image MIME type from binary content.
     * @param string $binary Image binary content.
     * @return string|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function detectMime(string $binary): ?string
    {
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        return null;
    }
}
