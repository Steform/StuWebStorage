<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static wiring checks for the visitor antibot gate feature.
 */
final class SiteAccessGateWiringContractTest extends TestCase
{
    /**
     * @brief Ensure antibot subscriber, controller, JS, and admin settings exist.
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
            'StorageBotSignalEvaluator',
            (string) file_get_contents($root.'/src/Controller/SiteAccessGateController.php')
        );
        self::assertStringContainsString(
            'storage-antibot-detection.js',
            (string) file_get_contents($root.'/templates/access_gate/index.html.twig')
        );
        self::assertStringContainsString(
            'access_gate_threshold',
            (string) file_get_contents($root.'/templates/admin/settings/_access_gate_card.html.twig')
        );
        self::assertStringContainsString(
            'CaptchaService',
            (string) file_get_contents($root.'/src/Service/Security/CaptchaService.php')
        );
    }
}
