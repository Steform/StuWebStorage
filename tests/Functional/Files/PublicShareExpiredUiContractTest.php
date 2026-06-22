<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contracts for effective public share state (listing, modals, JSON) after Sprint 22 decoupling.
 */
final class PublicShareExpiredUiContractTest extends TestCase
{
    /**
     * @brief Read repository-relative file contents.
     * @param string $relativePath Path from project root.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Folder entity exposes effective public policy helper aligned with listing/API.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFolderEntityDeclaresEffectivePublicShare(): void
    {
        $src = $this->readSource('src/Entity/Folder.php');
        self::assertStringContainsString('function isPublicShareEffectivelyActive(', $src);
    }

    /**
     * @brief SharedFile exposes listing clock helper tied to active link and finite expiry.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSharedFileDeclaresListingClockHelper(): void
    {
        $src = $this->readSource('src/Entity/SharedFile.php');
        self::assertStringContainsString('function shouldShowPublicExpirationClockInListing(', $src);
    }

    /**
     * @brief Repository counts active public files using is_public/public_expires_at aware DQL.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSharedFileRepositoryCountsActivePublicWithDecoupledColumns(): void
    {
        $src = $this->readSource('src/Repository/SharedFileRepository.php');
        self::assertStringContainsString('sf.isPublic = true', $src);
        self::assertStringContainsString('countActivePublicWithFiniteExpiryByOwnerAndFolderIds', $src);
    }

    /**
     * @brief FilesController share/state clears expires_at prefill when public link is inactive.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testShareStateJsonUsesActiveGateForExpiresAt(): void
    {
        $src = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString("'enabled' => \$sharedFile->isPublicShareActive()", $src);
        self::assertStringContainsString("'expires_at' => \$sharedFile->isPublicShareActive()", $src);
        self::assertStringContainsString("getEffectivePublicExpiresAtForOwnerUi()?->format(\\DateTimeInterface::ATOM)", $src);
    }

    /**
     * @brief Folder share/state JSON exposes effective enabled/active plus cleared expiry when inactive.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFolderShareStateUsesEffectivePublicFlag(): void
    {
        $src = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('$publicEffective = $folder->isPublicShareEffectivelyActive();', $src);
        self::assertStringContainsString("'active' => \$publicEffective", $src);
    }

    /**
     * @brief Public share modal JS prefers active flag for switch and expiry field prefill.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testFilesSpaceJsPrefillsPublicModalFromActive(): void
    {
        $src = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('typeof p.active === \'boolean\'', $src);
        self::assertStringContainsString('normalizeExpiresAtForDatetimeLocal(String(p.expires_at))', $src);
    }

    /**
     * @brief Listing fragment uses clock helper instead of raw hasPublicExpiration for files.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testListingFragmentUsesClockListingHelper(): void
    {
        $src = $this->readSource('templates/files/_listing_fragment.html.twig');
        self::assertStringContainsString('shouldShowPublicExpirationClockInListing', $src);
    }

    /**
     * @brief Anonymous landing controllers delegate access checks to PublicLandingAccessService.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testPublicLandingAccessServiceCentralizesAnonymousRules(): void
    {
        $src = $this->readSource('src/Service/Share/PublicLandingAccessService.php');
        self::assertStringContainsString('requireAccessiblePublicSharedFile', $src);
        self::assertStringContainsString('requireAccessiblePublicFolder', $src);
        self::assertStringContainsString('isPublicShareActive()', $src);
    }

    /**
     * @brief Public download JSON maps inactive file channel to 404 (not 410) for expired shares.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testPublicDownloadChallengeUses404ForInactiveFile(): void
    {
        $src = $this->readSource('src/Controller/PublicDownloadController.php');
        self::assertStringNotContainsString("'share.file.expired'", $src);
        self::assertStringContainsString('download.challenge.resource_not_found', $src);
    }
}
