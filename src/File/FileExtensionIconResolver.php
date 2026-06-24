<?php

declare(strict_types=1);

namespace App\File;

/**
 * @brief Resolve file extensions to Symfony UX vscode-icons names.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileExtensionIconResolver
{
    private readonly FileIconMappingProvider $mappingProvider;

    /**
     * @param FileIconMappingProvider|null $mappingProvider YAML-backed icon mappings (auto-created when omitted by stale prod cache).
     */
    public function __construct(
        ?FileIconMappingProvider $mappingProvider = null,
    ) {
        $this->mappingProvider = $mappingProvider ?? self::createDefaultMappingProvider();
    }

    /**
     * @brief Build mapping provider from project paths when DI container is stale.
     *
     * @param void No input parameter.
     * @return FileIconMappingProvider
     * @date 2026-06-24
     * @author Stephane H.
     */
    private static function createDefaultMappingProvider(): FileIconMappingProvider
    {
        $projectDir = dirname(__DIR__, 2);

        return new FileIconMappingProvider(
            $projectDir.'/config/icons/mappings',
            $projectDir.'/config/icons/categories.yaml',
        );
    }

    /**
     * @brief Resolve extension to UX icon descriptor.
     *
     * @param string $extension Raw file extension (with or without leading dot).
     * @return FileIconDescriptor
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolve(string $extension): FileIconDescriptor
    {
        $normalized = $this->normalizeExtension($extension);
        if ($normalized === '') {
            return $this->buildDescriptor('default-file', 'FILE', FileIconCategory::Default);
        }

        $normalized = $this->resolveCompoundExtension($normalized);
        $iconSuffix = $this->mappingProvider->getIconSuffix($normalized);
        if ($iconSuffix !== null) {
            return $this->buildDescriptor(
                $iconSuffix,
                strtoupper($normalized),
                $this->mappingProvider->getCategory($normalized),
            );
        }

        return $this->buildDescriptor('default-file', strtoupper($normalized), FileIconCategory::Default);
    }

    /**
     * @brief Resolve icon from a filename when extension is empty or insufficient.
     *
     * @param string $filename Full or base filename.
     * @param string $extension Optional raw extension fallback.
     * @return FileIconDescriptor
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolveByFilename(string $filename, string $extension = ''): FileIconDescriptor
    {
        $filenameEntry = $this->mappingProvider->getFilenameEntry($filename);
        if ($filenameEntry !== null) {
            $label = strtoupper(basename($filename));

            return $this->buildDescriptor($filenameEntry['icon'], $label, $filenameEntry['category']);
        }

        return $this->resolve($extension);
    }

    /**
     * @brief List unique vscode icon suffixes referenced by mappings.
     *
     * @param void No input parameter.
     * @return list<string>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function listUsedIconSuffixes(): array
    {
        return $this->mappingProvider->listAllIconSuffixes();
    }

    /**
     * @brief Normalize extension token.
     *
     * @param string $extension Raw extension.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function normalizeExtension(string $extension): string
    {
        $ext = strtolower(trim($extension));
        if (str_starts_with($ext, '.')) {
            $ext = substr($ext, 1);
        }

        return $ext;
    }

    /**
     * @brief Prefer trailing segment for compound extensions (e.g. tar.gz).
     *
     * @param string $extension Normalized extension.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function resolveCompoundExtension(string $extension): string
    {
        if (!str_contains($extension, '.')) {
            return $extension;
        }

        $segments = explode('.', $extension);
        $last = (string) end($segments);

        return $last !== '' ? $last : $extension;
    }

    /**
     * @brief Build descriptor with icon prefix applied.
     *
     * @param string $iconSuffix Icon suffix without prefix.
     * @param string $ariaLabel Accessible label.
     * @param FileIconCategory $category Icon family.
     * @return FileIconDescriptor
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildDescriptor(string $iconSuffix, string $ariaLabel, FileIconCategory $category): FileIconDescriptor
    {
        return new FileIconDescriptor(
            $this->mappingProvider->toIconName($iconSuffix),
            $ariaLabel,
            $category,
        );
    }
}
