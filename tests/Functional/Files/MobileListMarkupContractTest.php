<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for mobile stacked list markup in files listing templates.
 * @author Stephane H.
 * @date 2026-06-30
 */
final class MobileListMarkupContractTest extends TestCase
{
    /**
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-06-30
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return void
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function testListingFragmentDeclaresMobileOwnedListAndHidesDesktopTableOnSmallScreens(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString('d-none d-md-block table-responsive', $source);
        self::assertStringContainsString('files/components/mobile/_mobile_list_owned.html.twig', $source);
        self::assertStringContainsString('files/components/mobile/_mobile_list_shared.html.twig', $source);
    }

    /**
     * @return void
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function testMobileOwnedListPartialPreservesRowTargetHooks(): void
    {
        $source = $this->readSource('templates/files/components/mobile/_mobile_list_owned.html.twig');

        self::assertStringContainsString('files-mobile-list', $source);
        self::assertStringContainsString('d-md-none', $source);
        self::assertStringContainsString('data-files-row-target="{{ file.id }}"', $source);
        self::assertStringContainsString('_mobile_meta_share_owned.html.twig', $source);

        $meta = $this->readSource('templates/files/components/mobile/_mobile_meta_share_owned.html.twig');
        self::assertStringContainsString('data-files-mobile-meta="size"', $meta);
    }

    /**
     * @return void
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function testUserPaneTablesIncludeMobileListPartials(): void
    {
        $owned = $this->readSource('templates/files/components/_user_files_pane_owned_table.html.twig');
        $shared = $this->readSource('templates/files/components/_user_files_pane_shared_table.html.twig');

        self::assertStringContainsString('d-none d-md-block table-responsive', $owned);
        self::assertStringContainsString('_mobile_list_owned.html.twig', $owned);
        self::assertStringContainsString('d-none d-md-block table-responsive', $shared);
        self::assertStringContainsString('_mobile_list_shared.html.twig', $shared);
    }
}
