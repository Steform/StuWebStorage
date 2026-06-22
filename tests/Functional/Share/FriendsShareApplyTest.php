<?php

namespace App\Tests\Functional\Share;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract: friends share POST route exists.
 * @date 2026-04-28
 * @author Stephane H.
 */
final class FriendsShareApplyTest extends TestCase
{
    public function testFriendsShareRouteRegistered(): void
    {
        $c = (string) @file_get_contents(dirname(__DIR__, 3).'/src/Controller/FilesController.php');
        self::assertStringContainsString("name: 'files_share_friends'", $c);
    }
}
