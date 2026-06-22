<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contracts for admin godview target owner field and chunk retain propagation.
 * @author Stephane H.
 * @date 2026-05-04
 */
final class AdminTargetOwnerContractTest extends TestCase
{
    /**
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesIndexTwigDeclaresTargetOwnerWhenRequireAdmin(): void
    {
        $path = dirname(__DIR__, 3).'/templates/files/index.html.twig';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('requireAdminTargetOwner', $src);
        self::assertStringContainsString('name="target_owner_user_id"', $src);
        self::assertStringContainsString('name="target_folder_id"', $src);
        self::assertStringContainsString('data-require-target-owner', $src);
    }

    /**
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testListingRetainIncludesViewScopeAndPaneKeys(): void
    {
        $path = dirname(__DIR__, 3).'/templates/files/_listing_retain.html.twig';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('name="_retain_view_scope"', $src);
        self::assertStringContainsString('name="_retain_pane"', $src);
    }

    /**
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesSpaceJsPropagatesRetainOnChunkPost(): void
    {
        $path = dirname(__DIR__, 3).'/public/js/files-space.js';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('appendRetainFields(fdC, true)', $src);
        self::assertStringContainsString('initAdminTargetOwnerModals', $src);
        self::assertStringContainsString('syncUploadTargetFolderInput(formEl);', $src);
        self::assertStringContainsString("var key = 'uf_' + String(ownerId);", $src);
        self::assertStringContainsString("var requireOwner = adminViewScope === 'all' && viewScopeRaw === 'all';", $src);
        self::assertStringNotContainsString("var requireOwner = adminViewScope === 'all';", $src);
    }

    /**
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesControllerResolvesTargetOwnerAndFinalizeUsesIntOwner(): void
    {
        $path = dirname(__DIR__, 3).'/src/Controller/FilesController.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('function resolveUploadOrFolderTargetOwnerId(', $src);
        self::assertStringContainsString('function resolveUploadTargetFolderId(', $src);
        self::assertStringContainsString("files.flash.target_folder_invalid", $src);
        self::assertStringContainsString('isActiveUserWithShareOrAdminRole', $src);
        self::assertStringContainsString("return ['ownerId' => \$actorId];", $src);
        self::assertStringContainsString('finalizeAndPersist($ownerId, $uploadId, $maxUploadBytes)', $src);
        self::assertStringContainsString('function listingRedirectRouteName(', $src);
    }
}
