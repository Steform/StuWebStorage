<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\BugReportExtension;
use PHPUnit\Framework\TestCase;

class BugReportExtensionTest extends TestCase
{
    private BugReportExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new BugReportExtension();
    }

    /**
     * @brief Ensure excerpt returns placeholder for empty values.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testExcerptReturnsPlaceholderForEmptyValue(): void
    {
        self::assertSame('—', $this->extension->excerpt(null));
        self::assertSame('—', $this->extension->excerpt('   '));
    }

    /**
     * @brief Ensure excerpt keeps short values unchanged.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testExcerptKeepsShortValue(): void
    {
        self::assertSame('Short action', $this->extension->excerpt('Short action', 80));
    }

    /**
     * @brief Ensure excerpt truncates long values with ellipsis.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testExcerptTruncatesLongValue(): void
    {
        $value = str_repeat('a', 90);

        self::assertSame(str_repeat('a', 80).'…', $this->extension->excerpt($value, 80));
    }

    /**
     * @brief Ensure tooltip HTML escapes and preserves line breaks.
     * @param void No input parameter.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testTooltipHtmlEscapesAndPreservesLineBreaks(): void
    {
        self::assertStringContainsString('Line1<br />', $this->extension->tooltipHtml("Line1\nLine2"));
        self::assertStringContainsString('Line2', $this->extension->tooltipHtml("Line1\nLine2"));
        self::assertSame('&lt;script&gt;', $this->extension->tooltipHtml('<script>'));
    }
}
