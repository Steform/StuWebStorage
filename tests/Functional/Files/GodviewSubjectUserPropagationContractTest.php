<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for subject_user propagation in Godview share flows.
 * @date 2026-05-05
 * @author Stephane H.
 */
final class GodviewSubjectUserPropagationContractTest extends TestCase
{
    /**
     * @brief Read repository source file.
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-05-05
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Share modals must expose hidden subject_user fields for admin owner-target payload.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function testShareModalsExposeSubjectUserHiddenInputs(): void
    {
        $publicModal = $this->readSource('templates/files/_share_public_modal.html.twig');
        $friendsModal = $this->readSource('templates/files/_share_friends_modal.html.twig');

        self::assertStringContainsString('name="subject_user"', $publicModal);
        self::assertStringContainsString('data-files-share-subject-user-input', $publicModal);
        self::assertStringContainsString('name="subject_user"', $friendsModal);
        self::assertStringContainsString('data-files-share-subject-user-input', $friendsModal);
    }

    /**
     * @brief Files JS must build a unified share context and propagate admin/subject query payload.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesSpaceJsSynchronizesShareSubjectUser(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('function getSubjectUserIdFromTrigger', $source);
        self::assertStringContainsString('function buildFrontShareContext', $source);
        self::assertStringContainsString('function syncShareFormsContextInputs', $source);
        self::assertStringContainsString('function appendShareContextQuery', $source);
        self::assertStringContainsString('data-files-share-subject-user-input', $source);
        self::assertStringContainsString('data-files-share-admin-context-input', $source);
        self::assertStringContainsString('data-files-share-admin-view-scope-input', $source);
        self::assertStringContainsString('syncShareFormsContextInputs', $source);
        self::assertStringContainsString('admin_context=1', $source);
        self::assertStringContainsString('subject_user=', $source);
        self::assertStringContainsString('function downloadSharedSelection(ids, folderIds, selectionContext)', $source);
        self::assertStringContainsString("params.set('admin_context', '1');", $source);
        self::assertStringContainsString("params.set('subject_user', ctx.subjectUserId.trim());", $source);
        self::assertStringContainsString('function openFolderPropertiesModal(folderId, scope, subjectUserId)', $source);
        self::assertStringContainsString('modalEl.dataset.filesSharedFolderPropertiesUrlTemplate', $source);
        self::assertStringContainsString('urlTpl.replace(\'999999\', String(folderId))', $source);
        self::assertStringContainsString('appendShareContextQuery(', $source);
        self::assertStringContainsString('preserveAllUsersFriendsSubject', $source);
        self::assertStringContainsString("formEl.id === 'files-share-friends-form'", $source);
        self::assertStringContainsString("frozenAdminScope === 'all'", $source);
        self::assertStringContainsString('subjectInput.value = frozenSubjectUserId;', $source);
    }
}
