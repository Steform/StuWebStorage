<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for list tri-state sorting headers.
 */
final class ListTriStateSortingContractTest extends TestCase
{
    /**
     * @brief Read repository file as raw source.
     * @param string $relativePath Repo-relative path.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Listing fragment must render clickable tri-state headers in both list tables.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testListingFragmentContainsTriStateSortHeaders(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString("sortCycle", $source);
        self::assertStringContainsString("'sort': typeCycle.dir == '' ? null : 'type'", $source);
        self::assertStringContainsString("'sort': nameCycle.dir == '' ? null : 'name'", $source);
        self::assertStringContainsString("'sort': sizeCycle.dir == '' ? null : 'size'", $source);
        self::assertStringContainsString("'sort': uploadedCycle.dir == '' ? null : 'uploaded'", $source);
        self::assertStringContainsString("'sort': modifiedCycle.dir == '' ? null : 'modified'", $source);
        self::assertStringContainsString("'sort': publicCycle.dir == '' ? null : 'share_public'", $source);
        self::assertStringContainsString("'sort': friendsCycle.dir == '' ? null : 'share_friends'", $source);
        self::assertStringContainsString('aria-sort="{{ typeCycle.ariaSort }}"', $source);
        self::assertStringContainsString('aria-sort="{{ publicCycle.ariaSort }}"', $source);
        self::assertStringContainsString('aria-sort="{{ friendsCycle.ariaSort }}"', $source);
    }

    /**
     * @brief List tables must keep checkbox, type and name columns on the far left.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testListingFragmentKeepsLeftColumnOrder(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');

        self::assertMatchesRegularExpression(
            '/<th\s+scope="col"\s+class="files-col-select[^"]*"[\s\S]*?<th\s+scope="col"\s+class="files-col-icon[^"]*"\s+data-files-col="type"[\s\S]*?<th\s+scope="col"[^>]*aria-sort="{{ nameCycle\\.ariaSort }}"/',
            $source
        );
    }
}
