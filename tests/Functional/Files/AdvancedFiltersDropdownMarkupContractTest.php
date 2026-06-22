<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Contract: advanced filters modal uses dropdown checkbox groups for extensions and grantees.
 */
class AdvancedFiltersDropdownMarkupContractTest extends TestCase
{
    /**
     * @brief Ensure listing filters modal exposes checkbox arrays and dropdown hooks instead of select-multiple grantees.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testAdvancedFiltersTemplateUsesDropdownCheckboxFilters(): void
    {
        $path = dirname(__DIR__, 3).'/templates/files/index.html.twig';
        $src = (string) @file_get_contents($path);
        self::assertNotSame('', $src, 'index template readable');

        self::assertStringContainsString('data-files-adv-filter="extensions"', $src);
        self::assertStringContainsString('data-files-adv-filter="grantees"', $src);
        self::assertStringContainsString('name="ext[]"', $src);
        self::assertStringContainsString('type="checkbox"', $src);
        self::assertStringContainsString('name="grantee[]"', $src);
        self::assertStringContainsString('files-advanced-filters-i18n', $src);
        self::assertStringContainsString('data-bs-auto-close="outside"', $src);
        self::assertStringNotContainsString('<select class="form-select" id="adv-grantees"', $src);
        self::assertStringContainsString('files-filter-dropdown-scroll', $src);
    }
}
