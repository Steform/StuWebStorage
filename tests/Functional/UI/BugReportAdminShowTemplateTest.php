<?php

namespace App\Tests\Functional\UI;

use PHPUnit\Framework\TestCase;

class BugReportAdminShowTemplateTest extends TestCase
{
    /**
     * @brief Ensure admin bug report detail template uses readonly textareas.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testAdminShowTemplateUsesReadonlyTextareas(): void
    {
        $path = dirname(__DIR__, 3).'/templates/admin/bug_reports/show.html.twig';
        $source = is_readable($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString('bug-report-readonly', $source);
        self::assertStringContainsString('report.actionDescription', $source);
        self::assertStringContainsString('report.observedResult', $source);
        self::assertStringContainsString('report.expectedResult', $source);
        self::assertStringNotContainsString('<pre class="border rounded p-2 bg-body-tertiary">{{ report.actionDescription }}', $source);
        self::assertStringContainsString('container-fluid px-3 px-lg-4 py-4', $source);
        self::assertStringContainsString('admin_bug_reports_screenshot', $source);
    }
}
