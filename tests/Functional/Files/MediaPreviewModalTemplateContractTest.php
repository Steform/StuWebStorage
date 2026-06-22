<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract: global media preview modal markup (image, PDF iframe, native video/audio, plain text).
 * @date 2026-05-06
 * @author Stephane H.
 */
final class MediaPreviewModalTemplateContractTest extends TestCase
{
    public function testMediaPreviewModalTemplateMarkup(): void
    {
        $path = dirname(__DIR__, 3).'/templates/components/_media_preview_modal.html.twig';
        $s = (string) @file_get_contents($path);
        self::assertStringContainsString('id="mediaPreviewModal"', $s);
        self::assertStringContainsString('id="mediaPreviewBody"', $s);
        self::assertStringContainsString('id="mediaPreviewImage"', $s);
        self::assertStringContainsString('data-media-preview-role="image"', $s);
        self::assertStringContainsString('data-media-preview-role="pdf"', $s);
        self::assertStringContainsString('data-media-preview-role="video"', $s);
        self::assertStringContainsString('data-media-preview-default-title', $s);
        self::assertStringContainsString('id="mediaPreviewPdfFrame"', $s);
        self::assertStringContainsString('id="mediaPreviewPdfOpenTab"', $s);
        self::assertStringContainsString('data-media-preview-pdf-frame-title-default', $s);
        self::assertStringContainsString('id="mediaPreviewVideo"', $s);
        self::assertStringContainsString('id="mediaPreviewAudio"', $s);
        self::assertStringContainsString('id="mediaPreviewVideoWrap"', $s);
        self::assertStringContainsString('id="mediaPreviewVideoFullscreen"', $s);
        self::assertStringContainsString('id="mediaPreviewPlaybackError"', $s);
        self::assertStringContainsString('data-media-preview-role="audio"', $s);
        self::assertStringContainsString('data-media-preview-video-label', $s);
        self::assertStringContainsString('data-media-preview-audio-label', $s);
        self::assertStringContainsString('data-media-preview-role="text"', $s);
        self::assertStringContainsString('id="mediaPreviewText"', $s);
        self::assertStringContainsString('id="mediaPreviewTextLoadError"', $s);
        self::assertStringContainsString('data-media-preview-text-label', $s);
        self::assertStringContainsString('media.preview.text_label', $s);
        self::assertStringContainsString('media.preview.text_load_failed', $s);
    }
}
