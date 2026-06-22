<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static wiring checks for the site access gate feature.
 */
final class SiteAccessGateWiringContractTest extends TestCase
{
    /**
     * @brief Ensure gate subscriber, admin settings dashboard, and gate form exist.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testAccessGateWiringCompliance(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertStringContainsString(
            'SiteAccessGateSubscriber',
            (string) file_get_contents($root.'/src/EventSubscriber/SiteAccessGateSubscriber.php')
        );
        self::assertStringContainsString(
            'admin_settings_access_gate_update',
            (string) file_get_contents($root.'/src/Controller/Admin/SettingsController.php')
        );
        self::assertStringContainsString(
            'access_gate_bypass_note',
            (string) file_get_contents($root.'/templates/admin/settings/_access_gate_card.html.twig')
        );
        self::assertStringContainsString(
            'admin.settings.access_gate.title',
            (string) file_get_contents($root.'/templates/admin/settings/index.html.twig')
        );
        self::assertFileDoesNotExist($root.'/templates/home/_admin_access_gate_panel.html.twig');
    }
}
