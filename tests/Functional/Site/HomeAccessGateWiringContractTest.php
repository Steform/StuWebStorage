<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static wiring checks for the homepage antibot gate feature.
 */
final class HomeAccessGateWiringContractTest extends TestCase
{
    /**
     * @brief Ensure gate subscriber, controllers, and admin antibot form exist.
     *
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function testHomeAccessGateWiringCompliance(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertStringContainsString(
            'HomeAccessGateSubscriber',
            (string) file_get_contents($root.'/src/EventSubscriber/HomeAccessGateSubscriber.php')
        );
        self::assertStringContainsString(
            'storage_home_access',
            (string) file_get_contents($root.'/src/Controller/HomeAccessController.php')
        );
        self::assertStringContainsString(
            'storage_home_captcha',
            (string) file_get_contents($root.'/src/Controller/HomeCaptchaController.php')
        );
        self::assertStringContainsString(
            'antibot_threshold',
            (string) file_get_contents($root.'/templates/admin/settings/_antibot_card.html.twig')
        );
        self::assertStringContainsString(
            '_antibot_card.html.twig',
            (string) file_get_contents($root.'/templates/admin/settings/index.html.twig')
        );
        self::assertFileDoesNotExist($root.'/src/EventSubscriber/SiteAccessGateSubscriber.php');
        self::assertFileDoesNotExist($root.'/templates/admin/settings/_access_gate_card.html.twig');
    }
}
