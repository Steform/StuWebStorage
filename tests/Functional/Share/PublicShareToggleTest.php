<?php

namespace App\Tests\Functional\Share;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract: public share POST route and CSRF id exist in FilesController.
 * @date 2026-04-28
 * @author Stephane H.
 */
final class PublicShareToggleTest extends TestCase
{
    /**
     * @brief Ensure modern public-share routes include password toggle endpoints for files and folders.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testPublicShareRouteRegistered(): void
    {
        $c = (string) @file_get_contents(dirname(__DIR__, 3).'/src/Controller/FilesController.php');
        self::assertStringContainsString("name: 'files_share_public'", $c);
        self::assertStringContainsString("name: 'files_folder_share_public'", $c);
        self::assertStringContainsString("name: 'files_share_public_password_toggle'", $c);
        self::assertStringContainsString("name: 'files_folder_share_public_password_toggle'", $c);
        self::assertStringContainsString("'password_enabled' => \$passwordEnabled", $c);
        self::assertStringContainsString("'public_password_plain'", $c);
        self::assertStringContainsString("'password_copy_available' =>", $c);
    }
}
