<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for preserving godview all-users subject user on friends share submit.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class AdminAllUsersFriendsShareSubjectPreservationContractTest extends TestCase
{
    /**
     * @brief Read repository source file.
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Friends share submit must preserve subject_user hidden input in admin all-users scope.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testSubmitShareFormPreservesAllUsersSubjectForFriendsModal(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('function submitShareFormAsJson(formEl, modalIdToHide)', $source);
        self::assertStringContainsString("formEl.id === 'files-share-friends-form'", $source);
        self::assertStringContainsString("frozenAdminContext === '1'", $source);
        self::assertStringContainsString("frozenAdminScope === 'all'", $source);
        self::assertStringContainsString('frozenSubjectUserId !== \'\'', $source);
        self::assertStringContainsString('if (preserveAllUsersFriendsSubject) {', $source);
        self::assertStringContainsString('subjectInput.value = frozenSubjectUserId;', $source);
        self::assertStringContainsString('var explicitSubject = typeof subjectUserId === \'string\' ? subjectUserId.trim() : \'\';', $source);
        self::assertStringContainsString('var activeSubject = getSubjectUserIdFromActivePane();', $source);
        self::assertStringContainsString('explicitSubjectUserId: explicitSubject', $source);
    }
}
