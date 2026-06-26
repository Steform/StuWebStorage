<?php

namespace App\Tests\Functional\UI;

use PHPUnit\Framework\TestCase;

class BugReportAdminListTemplateTest extends TestCase
{
    /**
     * @brief Ensure admin bug report list template exposes triage columns and tooltips.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testAdminListTemplateContainsTriageLayout(): void
    {
        $path = dirname(__DIR__, 3).'/templates/admin/bug_reports/index.html.twig';
        $source = is_readable($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString('bug-reports-table', $source);
        self::assertStringContainsString('container-fluid px-3 px-lg-4 py-4', $source);
        self::assertStringContainsString('bug_report_excerpt(80)', $source);
        self::assertStringContainsString('bug_report_tooltip_html', $source);
        self::assertStringContainsString('data-bs-toggle="tooltip"', $source);
        self::assertStringContainsString('d-none d-lg-table-cell', $source);
        self::assertStringContainsString('admin_bug_reports_status', $source);
        self::assertStringContainsString('data-bug-report-resolve-form', $source);
        self::assertStringContainsString('admin_bug_reports_screenshot', $source);
        self::assertStringContainsString('data-files-media-preview-trigger', $source);
        self::assertStringContainsString('data-media-preview-type="image"', $source);
        self::assertStringContainsString('admin_bug_reports_archive', $source);
        self::assertStringContainsString("report.status == 'resolved' and not report.archived", $source);
        self::assertStringContainsString('bugReportArchiveModal', $source);
        self::assertStringContainsString('data-bug-report-archive-url', $source);
        self::assertStringContainsString('_archive_modal.html.twig', $source);
    }
}
