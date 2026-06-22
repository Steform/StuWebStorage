<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static backend contract checks for share columns sort support.
 */
final class ShareColumnsSortBackendContractTest extends TestCase
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
     * @brief Controller sort whitelist must include share_public and share_friends.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testControllerSortWhitelistContainsShareColumns(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("'share_public'", $source);
        self::assertStringContainsString("'share_friends'", $source);
    }

    /**
     * @brief Repository order map must support share_public and share_friends sort branches.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testRepositoryOrderMapContainsShareSortBranches(): void
    {
        $source = $this->readSource('src/Repository/SharedFileRepository.php');

        self::assertStringContainsString("case 'share_public':", $source);
        self::assertStringContainsString("case 'share_friends':", $source);
        self::assertStringContainsString('sortHasFriendsShare', $source);
        self::assertStringContainsString("addOrderBy('sf.id', 'DESC')", $source);
    }
}
