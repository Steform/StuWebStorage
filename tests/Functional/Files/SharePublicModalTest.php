<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract: public share modal id and form exist in Twig.
 * @date 2026-04-28
 * @author Stephane H.
 */
final class SharePublicModalTest extends TestCase
{
    /**
     * @brief Ensure public share modal exposes subject_user hidden input for Godview impersonation payloads.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function testSharePublicModalTemplateExists(): void
    {
        $s = (string) @file_get_contents(dirname(__DIR__, 3).'/templates/files/_share_public_modal.html.twig');
        self::assertStringContainsString('id="filesSharePublicModal"', $s);
        self::assertStringContainsString('data-files-folder-share-state-url-template', $s);
        self::assertStringContainsString('data-files-share-public-password-toggle-single-url-template', $s);
        self::assertStringContainsString('data-files-share-public-password-toggle-folder-url-template', $s);
        self::assertStringContainsString('999999', $s);
        self::assertStringContainsString('name="admin_context"', $s);
        self::assertStringContainsString('name="admin_view_scope"', $s);
        self::assertStringContainsString('data-files-share-admin-context-input', $s);
        self::assertStringContainsString('data-files-share-admin-view-scope-input', $s);
        self::assertStringContainsString('name="subject_user"', $s);
        self::assertStringContainsString('data-files-share-subject-user-input', $s);
        self::assertStringContainsString('id="files-share-public-password-enabled"', $s);
        self::assertStringContainsString('data-files-share-public-password-display', $s);
    }
}
