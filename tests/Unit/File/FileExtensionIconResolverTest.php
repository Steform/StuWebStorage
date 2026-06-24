<?php

declare(strict_types=1);

namespace App\Tests\Unit\File;

use App\File\FileExtensionIconResolver;
use App\File\FileIconCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for file extension to UX icon resolution.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileExtensionIconResolverTest extends TestCase
{
    private FileExtensionIconResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FileExtensionIconResolver();
    }

    /**
     * @brief Extension resolves to expected vscode icon name.
     *
     * @param string $extension Raw extension input.
     * @param string $expectedIconName Full UX icon identifier.
     * @param FileIconCategory $expectedCategory Expected icon family.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[DataProvider('extensionProvider')]
    public function testResolveMapsExtensionToIcon(
        string $extension,
        string $expectedIconName,
        FileIconCategory $expectedCategory,
    ): void {
        $descriptor = $this->resolver->resolve($extension);

        self::assertSame($expectedIconName, $descriptor->iconName);
        self::assertSame($expectedCategory, $descriptor->category);
    }

    /**
     * @brief Provider for extension resolution cases.
     *
     * @return iterable<string, array{string, string, FileIconCategory}>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public static function extensionProvider(): iterable
    {
        yield 'word' => ['docx', 'vscode:file-type-word', FileIconCategory::OfficeWord];
        yield 'excel' => ['xlsx', 'vscode:file-type-excel', FileIconCategory::OfficeExcel];
        yield 'powerpoint' => ['pptx', 'vscode:file-type-powerpoint', FileIconCategory::OfficePowerpoint];
        yield 'pdf' => ['pdf', 'vscode:file-type-pdf', FileIconCategory::Pdf];
        yield 'zip' => ['zip', 'vscode:file-type-zip', FileIconCategory::Archive];
        yield 'mp4' => ['mp4', 'vscode:file-type-video', FileIconCategory::Video];
        yield 'mp3' => ['mp3', 'vscode:file-type-audio', FileIconCategory::Audio];
        yield 'php' => ['php', 'vscode:file-type-php', FileIconCategory::Code];
        yield 'png' => ['png', 'vscode:file-type-image', FileIconCategory::Image];
        yield 'json' => ['json', 'vscode:file-type-json', FileIconCategory::Code];
        yield 'sqlite' => ['sqlite3', 'vscode:file-type-sqlite', FileIconCategory::Database];
        yield 'uppercase' => ['.PDF', 'vscode:file-type-pdf', FileIconCategory::Pdf];
        yield 'compound' => ['archive.tar.gz', 'vscode:file-type-zip', FileIconCategory::Archive];
        yield 'unknown' => ['weirdext', 'vscode:default-file', FileIconCategory::Default];
        yield 'empty' => ['', 'vscode:default-file', FileIconCategory::Default];
    }

    /**
     * @brief Export list contains default fallback icon.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testListUsedIconSuffixesIncludesDefaultFile(): void
    {
        self::assertContains('default-file', $this->resolver->listUsedIconSuffixes());
        self::assertContains('file-type-pdf', $this->resolver->listUsedIconSuffixes());
    }
}
