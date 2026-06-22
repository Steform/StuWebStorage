<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for admin godview file actions and audit hooks.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class AdminGodviewActionsContractTest extends TestCase
{
    /**
     * @brief Read repo file contents as a raw string.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Delete, rename and share actions must allow ROLE_ADMIN owner-target override and emit admin audit events.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function testAdminOverrideAndAuditExistOnGodviewActions(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('tryResolveEffectiveOwnerIdForAdminSubject', $source);
        self::assertStringContainsString('files.flash.godview_subject_invalid', $source);
    }

    /**
     * @brief Share-state and properties endpoints must resolve owner through admin subject context.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function testAdminOwnerReadEndpointsUseSubjectResolver(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('public function shareState(Request $request, int $id, TranslatorInterface $translator): JsonResponse', $source);
        self::assertStringContainsString('public function properties(Request $request, int $id, TranslatorInterface $translator): JsonResponse', $source);
        self::assertStringContainsString('public function folderShareState(Request $request, int $id, TranslatorInterface $translator): JsonResponse', $source);
        self::assertStringContainsString('public function folderProperties(Request $request, int $id): JsonResponse', $source);
        self::assertStringContainsString('tryResolveEffectiveOwnerIdForAdminSubject($request, true)', $source);
        self::assertStringContainsString("#[Route('/files/{id}/share/state'", $source);
        self::assertStringContainsString("#[Route('/files/{id}/properties'", $source);
    }

    /**
     * @brief Listing retain template must preserve admin context across POST actions.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testListingRetainCarriesAdminContext(): void
    {
        $source = $this->readSource('templates/files/_listing_retain.html.twig');

        self::assertStringContainsString('name="_retain_admin_context"', $source);
        self::assertStringContainsString('name="_retain_admin_view_scope"', $source);
        self::assertStringContainsString('name="_retain_owner"', $source);
        self::assertStringContainsString('name="_retain_owner_query"', $source);
    }
}
