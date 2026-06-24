<?php

namespace App\Tests\Functional\UI;

use PHPUnit\Framework\TestCase;

class ThemeLayoutIntegrationTest extends TestCase
{
    /**
     * @brief Ensure base layout exposes theme hooks and floating actions include.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testBaseLayoutContainsThemeHooks(): void
    {
        $root = dirname(__DIR__, 3);
        $baseTemplate = file_get_contents($root.'/templates/base.html.twig') ?: '';

        self::assertStringContainsString("app.request.attributes.get('app_theme', 'light')", $baseTemplate);
        self::assertStringContainsString('data-bs-theme="{{ currentTheme }}"', $baseTemplate);
        self::assertStringContainsString("components/_floating_actions.html.twig", $baseTemplate);
        self::assertStringContainsString("css/floating-actions.css", $baseTemplate);
        self::assertStringContainsString('js/bug-report-tracker.js', $baseTemplate);
    }

    /**
     * @brief Ensure storage landing layout inherits floating actions from base.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testStorageLandingExtendsBaseLayout(): void
    {
        $root = dirname(__DIR__, 3);
        $landingTemplate = file_get_contents($root.'/templates/layouts/storage_landing.html.twig') ?: '';

        self::assertStringContainsString("extends 'base.html.twig'", $landingTemplate);
    }
}
