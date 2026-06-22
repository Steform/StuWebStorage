<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract checks for target owner requirement in admin me-scope vs all-users scope.
 * @date 2026-05-07
 * @author Stephane H.
 */
final class AdminTargetOwnerMeScopeContractTest extends TestCase
{
    /**
     * @brief Read files-space script source for substring assertions.
     * @return string
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function readJsSource(): string
    {
        $path = dirname(__DIR__, 3).'/public/js/files-space.js';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Require-owner condition must be strict all/all and not all/*.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testRequireOwnerUsesAdminAllAndViewAll(): void
    {
        $src = $this->readJsSource();

        self::assertStringContainsString("var requireOwner = adminViewScope === 'all' && viewScopeRaw === 'all';", $src);
        self::assertStringNotContainsString("var requireOwner = adminViewScope === 'all';", $src);
    }

    /**
     * @brief Script must still preserve show/wire and hide/reset modal flows.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testModalOwnerFlowStillTogglesAndResets(): void
    {
        $src = $this->readJsSource();

        self::assertStringContainsString("modalRoot.setAttribute('data-require-target-owner', '1');", $src);
        self::assertStringContainsString('initAdminTargetOwnerModals();', $src);
        self::assertStringContainsString("modalRoot.setAttribute('data-require-target-owner', '0');", $src);
        self::assertStringContainsString("modalRoot.removeAttribute('data-files-target-owner-wired');", $src);
        self::assertStringContainsString("inp.value = '';", $src);
        self::assertStringContainsString('btn.disabled = false;', $src);
    }
}
