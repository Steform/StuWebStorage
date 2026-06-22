<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contracts for ROLE_SHARE (receive) vs ROLE_SHARE_SEND (owned/upload) split.
 */
final class ShareSendRoleContractTest extends TestCase
{
    /**
     * @brief Load FilesController source for substring assertions.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function readFilesController(): string
    {
        $path = dirname(__DIR__, 3).'/src/Controller/FilesController.php';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Listing index stays ROLE_SHARE while mutations use ROLE_SHARE_SEND.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilesIndexAndUploadAnnotationsSplitRoles(): void
    {
        $src = $this->readFilesController();

        self::assertStringContainsString("name: 'files_index'", $src);
        self::assertStringContainsString("#[IsGranted('ROLE_SHARE')]", $src);
        self::assertStringContainsString("listing_scope'] = 'shared'", $src);
        self::assertStringContainsString("name: 'files_upload'", $src);
        self::assertStringContainsString("name: 'files_upload_session'", $src);
        self::assertStringContainsString("name: 'files_upload_chunk'", $src);
        self::assertStringContainsString("name: 'files_upload_finalize'", $src);
        self::assertStringContainsString("#[IsGranted('ROLE_SHARE_SEND')]", $src);
    }

    /**
     * @brief Grantee autocomplete requires sender + friends roles on the action.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testGranteeSuggestRequiresSendAndFriends(): void
    {
        $src = $this->readFilesController();

        self::assertStringContainsString("name: 'files_grantee_search'", $src);
        self::assertStringContainsString('function granteeSuggest', $src);
        self::assertStringContainsString("#[IsGranted('ROLE_SHARE_SEND')]", $src);
        self::assertStringContainsString("#[IsGranted('ROLE_SHARE_FRIENDS')]", $src);
    }
}
