<?php

namespace App\Tests\Functional\Share;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract for the shareable public URL (/p/{token}) and the
 *        "copy public link" row action (Sprint 22).
 * @author Stephane H.
 * @date 2026-04-28
 */
final class PublicFileLandingContractTest extends TestCase
{
    /**
     * @param string $relativePath Repository-relative path to the file.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function readFile(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root . DIRECTORY_SEPARATOR . $relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testDropdownConditionallyRendersCopyPublicLinkWithUrl(): void
    {
        $source = $this->readFile('templates/files/_file_actions_dropdown.html.twig');
        self::assertNotSame('', $source);
        self::assertStringContainsString('file.isPublicShareActive', $source);
        self::assertStringContainsString('data-files-row-action="copy-public-link"', $source);
        self::assertStringContainsString("file_public_landing", $source);
        self::assertStringContainsString('data-files-public-url=', $source);
    }

    /**
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testSecurityGrantsPublicAccessToPublicLandingPrefix(): void
    {
        $source = $this->readFile('config/packages/security.yaml');
        self::assertNotSame('', $source);
        self::assertStringContainsString('^/p/', $source);
        self::assertStringContainsString('^/download/public', $source);
        self::assertStringContainsString('PUBLIC_ACCESS', $source);
    }

    /**
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testPublicLandingPageIncludesMetadataAndTotpForm(): void
    {
        $source = $this->readFile('templates/public_file/landing.html.twig');
        self::assertNotSame('', $source);
        self::assertStringContainsString('id="public-landing-root"', $source);
        self::assertStringContainsString('public-file-landing.js', $source);
        self::assertStringContainsString('data-endpoint-challenge', $source);
        self::assertStringContainsString('file_public_preview', $source);
    }

    /**
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesSpaceJsHandlesCopyPublicLinkAction(): void
    {
        $source = $this->readFile('public/js/files-space.js');
        self::assertNotSame('', $source);
        self::assertStringContainsString('copy-public-link', $source);
        self::assertStringContainsString('files-copy-live', $source);
        self::assertStringContainsString('password_copy_available', $source);
        self::assertStringContainsString('data-msg-password-unavailable', $source);
    }

    /**
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testIndexExposesCopyFeedbackLiveRegion(): void
    {
        $source = $this->readFile('templates/files/index.html.twig');
        self::assertNotSame('', $source);
        self::assertStringContainsString('id="files-copy-live"', $source);
        self::assertStringContainsString('data-msg-password-unavailable', $source);
    }
}
