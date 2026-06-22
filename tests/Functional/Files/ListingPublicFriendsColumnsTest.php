<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract: listing fragment includes Public and Shared (friends) column headers and Bootstrap icon hook.
 * @date 2026-04-28
 * @author Stephane H.
 */
final class ListingPublicFriendsColumnsTest extends TestCase
{
    public function testListingTableHasPublicAndFriendsColumns(): void
    {
        $s = (string) @file_get_contents(dirname(__DIR__, 3).'/templates/files/_listing_fragment.html.twig');
        self::assertStringContainsString("'files.table.share_public'|trans", $s);
        self::assertStringContainsString("'files.table.share_friends'|trans", $s);
        self::assertStringContainsString('bi-clock-history', $s);
    }

    /**
     * @brief Shared-for-me list table must hide uploaded/modified/public/friends columns in thead and use colspan 5 for empty state.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testSharedForMeListHidesUploadedModifiedAndShareColumns(): void
    {
        $s = (string) @file_get_contents(dirname(__DIR__, 3).'/templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString('colspan="6"', $s);
        self::assertStringContainsString('files.section.shared_for_me_empty', $s);

        $marker = 'files-shared-list-table';
        $start = strpos($s, $marker);
        self::assertNotFalse($start, 'Shared-for-me list table marker must exist.');
        $theadStart = strpos($s, '<thead', $start);
        self::assertNotFalse($theadStart);
        $theadEnd = strpos($s, '</thead>', $theadStart);
        self::assertNotFalse($theadEnd);
        $sharedThead = substr($s, $theadStart, $theadEnd - $theadStart + \strlen('</thead>'));
        self::assertStringNotContainsString('data-files-col="uploaded"', $sharedThead);
        self::assertStringNotContainsString('data-files-col="modified"', $sharedThead);
        self::assertStringNotContainsString('data-files-col="share_public"', $sharedThead);
        self::assertStringNotContainsString('data-files-col="share_friends"', $sharedThead);
    }
}
