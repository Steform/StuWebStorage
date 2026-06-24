<?php

declare(strict_types=1);

namespace App\Tests\Unit\File;

use App\File\FileExtensionIconResolver;
use App\File\FileIconCategory;
use App\File\FileIconMappingProvider;
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
        $root = dirname(__DIR__, 3);
        $this->resolver = new FileExtensionIconResolver(
            new FileIconMappingProvider(
                $root.'/config/icons/mappings',
                $root.'/config/icons/categories.yaml',
            ),
        );
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
        yield 'libreoffice-writer' => ['odt', 'vscode:file-type-libreoffice-writer', FileIconCategory::OfficeWord];
        yield 'libreoffice-calc' => ['ods', 'vscode:file-type-libreoffice-calc', FileIconCategory::OfficeExcel];
        yield 'libreoffice-impress' => ['odp', 'vscode:file-type-libreoffice-impress', FileIconCategory::OfficePowerpoint];
        yield 'libreoffice-draw' => ['odg', 'vscode:file-type-libreoffice-draw', FileIconCategory::OfficePowerpoint];
        yield 'libreoffice-base' => ['odb', 'vscode:file-type-libreoffice-base', FileIconCategory::Database];
        yield 'visio' => ['vsdx', 'vscode:file-type-drawio', FileIconCategory::OfficePowerpoint];
        yield 'publisher' => ['pub', 'vscode:file-type-publisher', FileIconCategory::OfficeWord];
        yield 'outlook' => ['pst', 'vscode:file-type-outlook', FileIconCategory::Email];
        yield 'infopath' => ['xsn', 'vscode:file-type-infopath', FileIconCategory::OfficeWord];
        yield 'access' => ['accdb', 'vscode:file-type-access', FileIconCategory::Database];
        yield 'pdf' => ['pdf', 'vscode:file-type-pdf2', FileIconCategory::Pdf];
        yield 'zip' => ['zip', 'vscode:file-type-zip', FileIconCategory::Archive];
        yield 'mp4' => ['mp4', 'vscode:file-type-video', FileIconCategory::Video];
        yield 'mkv' => ['mkv', 'vscode:file-type-video', FileIconCategory::Video];
        yield 'vob' => ['vob', 'vscode:file-type-video', FileIconCategory::Video];
        yield 'rmvb' => ['rmvb', 'vscode:file-type-video', FileIconCategory::Video];
        yield 'mts' => ['mts', 'vscode:file-type-video', FileIconCategory::Video];
        yield 'hevc' => ['hevc', 'vscode:file-type-video', FileIconCategory::Video];
        yield 'mp3' => ['mp3', 'vscode:file-type-audio', FileIconCategory::Audio];
        yield 'wav' => ['wav', 'vscode:file-type-audio', FileIconCategory::Audio];
        yield 'wave' => ['wave', 'vscode:file-type-audio', FileIconCategory::Audio];
        yield 'flac' => ['flac', 'vscode:file-type-audio', FileIconCategory::Audio];
        yield 'aiff' => ['aiff', 'vscode:file-type-audio', FileIconCategory::Audio];
        yield 'tracker-mod' => ['mod', 'vscode:file-type-audio', FileIconCategory::Audio];
        yield '7z' => ['7z', 'vscode:file-type-zip2', FileIconCategory::Archive];
        yield 'rar' => ['rar', 'vscode:file-type-zip2', FileIconCategory::Archive];
        yield 'deb' => ['deb', 'vscode:file-type-debian', FileIconCategory::Archive];
        yield 'iso' => ['iso', 'vscode:file-type-zip', FileIconCategory::Archive];
        yield 'nupkg' => ['nupkg', 'vscode:file-type-nuget', FileIconCategory::Archive];
        yield 'cpp' => ['cpp', 'vscode:file-type-cpp', FileIconCategory::Code];
        yield 'hpp' => ['hpp', 'vscode:file-type-cpp', FileIconCategory::Code];
        yield 'zig' => ['zig', 'vscode:file-type-zig', FileIconCategory::Code];
        yield 'elixir' => ['ex', 'vscode:file-type-elixir', FileIconCategory::Code];
        yield 'terraform' => ['tf', 'vscode:file-type-terraform', FileIconCategory::Code];
        yield 'graphql' => ['graphql', 'vscode:file-type-graphql', FileIconCategory::Code];
        yield 'astro' => ['astro', 'vscode:file-type-astro', FileIconCategory::Code];
        yield 'avif' => ['avif', 'vscode:file-type-avif', FileIconCategory::Image];
        yield 'ebook-epub' => ['epub', 'vscode:file-type-epub', FileIconCategory::Ebook];
        yield 'subtitle-srt' => ['srt', 'vscode:file-type-text', FileIconCategory::Subtitle];
        yield 'mysql' => ['mysql', 'vscode:file-type-mysql', FileIconCategory::Database];
        yield 'gltf' => ['gltf', 'vscode:file-type-gltf', FileIconCategory::Cad];
        yield 'gpg' => ['gpg', 'vscode:file-type-gpg', FileIconCategory::Certificate];
        yield 'php' => ['php', 'vscode:file-type-php', FileIconCategory::Code];
        yield 'png' => ['png', 'vscode:file-type-image', FileIconCategory::Image];
        yield 'json' => ['json', 'vscode:file-type-json', FileIconCategory::Code];
        yield 'sqlite' => ['sqlite3', 'vscode:file-type-sqlite', FileIconCategory::Database];
        yield 'uppercase' => ['.PDF', 'vscode:file-type-pdf2', FileIconCategory::Pdf];
        yield 'compound' => ['archive.tar.gz', 'vscode:file-type-zip', FileIconCategory::Archive];
        yield 'unknown' => ['weirdext', 'vscode:default-file', FileIconCategory::Default];
        yield 'empty' => ['', 'vscode:default-file', FileIconCategory::Default];
    }

    /**
     * @brief Extensionless filenames resolve through filename map.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testResolveByFilenameMapsDockerfile(): void
    {
        $descriptor = $this->resolver->resolveByFilename('Dockerfile', '');

        self::assertSame('vscode:file-type-docker', $descriptor->iconName);
        self::assertSame(FileIconCategory::Code, $descriptor->category);
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
        self::assertContains('file-type-pdf2', $this->resolver->listUsedIconSuffixes());
    }
}
