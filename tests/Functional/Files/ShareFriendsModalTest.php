<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract: friends share modal id exists in Twig.
 * @date 2026-04-28
 * @author Stephane H.
 */
final class ShareFriendsModalTest extends TestCase
{
    /**
     * @brief Ensure friends share modal exposes subject_user hidden input for Godview impersonation payloads.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function testShareFriendsModalTemplateExists(): void
    {
        $s = (string) @file_get_contents(dirname(__DIR__, 3).'/templates/files/_share_friends_modal.html.twig');
        self::assertStringContainsString('id="filesShareFriendsModal"', $s);
        self::assertStringContainsString('name="admin_context"', $s);
        self::assertStringContainsString('name="admin_view_scope"', $s);
        self::assertStringContainsString('data-files-share-admin-context-input', $s);
        self::assertStringContainsString('data-files-share-admin-view-scope-input', $s);
        self::assertStringContainsString('name="subject_user"', $s);
        self::assertStringContainsString('data-files-share-subject-user-input', $s);
    }
}
