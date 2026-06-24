<?php

namespace App\Tests\Functional\UI;

use PHPUnit\Framework\TestCase;

class BugReportModalTemplateTest extends TestCase
{
    /**
     * @brief Ensure floating actions template declares bug report modal fields.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testFloatingActionsTemplateContainsBugModalFields(): void
    {
        $path = dirname(__DIR__, 3).'/templates/components/_floating_actions.html.twig';
        $source = is_readable($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString("id=\"bugReportModal\"", $source);
        self::assertStringContainsString('name="action_description"', $source);
        self::assertStringContainsString('name="observed_result"', $source);
        self::assertStringContainsString('name="expected_result"', $source);
        self::assertStringContainsString("path('bug_report_submit')", $source);
    }
}
