<?php

declare(strict_types=1);

namespace App\File;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * @brief Loads and serves file extension to vscode icon mappings from YAML config.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileIconMappingProvider
{
    private const ICON_PREFIX = 'vscode:';

    /**
     * @var list<string>
     */
    private const MAPPING_FILES = [
        'office.yaml',
        'documents.yaml',
        'images.yaml',
        'video.yaml',
        'audio.yaml',
        'archives.yaml',
        'code_web.yaml',
        'code_systems.yaml',
        'code_scripting.yaml',
        'code_infra.yaml',
        'database.yaml',
        'cad_3d.yaml',
        'gis.yaml',
        'fonts.yaml',
        'security.yaml',
        'email.yaml',
        'subtitles.yaml',
        'executables.yaml',
        'overrides.yaml',
    ];

    /**
     * @var array<string, array{icon: string, category: string}>|null
     */
    private ?array $extensions = null;

    /**
     * @var array<string, array{icon: string, category: string}>|null
     */
    private ?array $filenames = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $categoryIcons = null;

    /**
     * @param string $mappingsDir Absolute path to config/icons/mappings.
     * @param string $categoriesFile Absolute path to config/icons/categories.yaml.
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/icons/mappings')]
        private readonly string $mappingsDir,
        #[Autowire('%kernel.project_dir%/config/icons/categories.yaml')]
        private readonly string $categoriesFile,
    ) {
    }

    /**
     * @brief Resolve icon suffix for a normalized file extension.
     *
     * @param string $extension Normalized extension without leading dot.
     * @return string|null Icon suffix or null when unknown.
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getIconSuffix(string $extension): ?string
    {
        return $this->getExtensionEntry($extension)['icon'] ?? null;
    }

    /**
     * @brief Resolve category value for a normalized file extension.
     *
     * @param string $extension Normalized extension without leading dot.
     * @return FileIconCategory
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getCategory(string $extension): FileIconCategory
    {
        $category = $this->getExtensionEntry($extension)['category'] ?? null;

        return $category !== null
            ? FileIconCategory::from($category)
            : FileIconCategory::Default;
    }

    /**
     * @brief Resolve mapping from a full filename (e.g. Dockerfile).
     *
     * @param string $filename Base filename.
     * @return array{icon: string, category: FileIconCategory}|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getFilenameEntry(string $filename): ?array
    {
        $this->loadFilenames();
        $basename = basename($filename);
        $entry = $this->filenames[$basename] ?? null;
        if ($entry === null) {
            return null;
        }

        return [
            'icon' => $entry['icon'],
            'category' => FileIconCategory::from($entry['category']),
        ];
    }

    /**
     * @brief List unique vscode icon suffixes referenced by mappings and category fallbacks.
     *
     * @param void No input parameter.
     * @return list<string>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function listAllIconSuffixes(): array
    {
        $this->loadExtensions();
        $suffixes = array_map(static fn (array $entry): string => $entry['icon'], $this->extensions ?? []);
        foreach ($this->getCategoryIcons() as $iconSuffix) {
            $suffixes[] = $iconSuffix;
        }

        $suffixes = array_unique($suffixes);
        sort($suffixes);

        return array_values($suffixes);
    }

    /**
     * @brief Return all loaded extension keys for validation.
     *
     * @param void No input parameter.
     * @return array<string, array{icon: string, category: string, source: string}>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function listAllExtensionEntries(): array
    {
        $this->loadExtensions();
        $result = [];
        foreach ($this->extensions ?? [] as $extension => $entry) {
            $result[$extension] = [
                'icon' => $entry['icon'],
                'category' => $entry['category'],
                'source' => $entry['source'],
            ];
        }

        return $result;
    }

    /**
     * @brief Category fallback icon suffixes.
     *
     * @param void No input parameter.
     * @return array<string, string>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getCategoryIcons(): array
    {
        if ($this->categoryIcons !== null) {
            return $this->categoryIcons;
        }

        $data = Yaml::parseFile($this->categoriesFile);
        $icons = $data['category_icons'] ?? [];
        if (!\is_array($icons)) {
            throw new \RuntimeException('Invalid categories.yaml: category_icons must be a map.');
        }

        /** @var array<string, string> $icons */
        $this->categoryIcons = $icons;

        return $this->categoryIcons;
    }

    /**
     * @brief Full UX icon name for a suffix.
     *
     * @param string $iconSuffix Icon suffix without prefix.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function toIconName(string $iconSuffix): string
    {
        return self::ICON_PREFIX.$iconSuffix;
    }

    /**
     * @brief Absolute mappings directory path.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getMappingsDir(): string
    {
        return $this->mappingsDir;
    }

    /**
     * @brief Load extension map from YAML files.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function loadExtensions(): void
    {
        if ($this->extensions !== null) {
            return;
        }

        $extensions = [];
        foreach (self::MAPPING_FILES as $file) {
            $path = $this->mappingsDir.'/'.$file;
            if (!is_readable($path)) {
                throw new \RuntimeException(sprintf('Missing icon mapping file: %s', $path));
            }

            $data = Yaml::parseFile($path);
            $entries = $data['extensions'] ?? [];
            if (!\is_array($entries)) {
                throw new \RuntimeException(sprintf('Invalid mapping file %s: extensions must be a map.', $file));
            }

            foreach ($entries as $extension => $entry) {
                if (!\is_string($extension) || !\is_array($entry)) {
                    continue;
                }

                $icon = $entry['icon'] ?? null;
                $category = $entry['category'] ?? null;
                if (!\is_string($icon) || !\is_string($category)) {
                    throw new \RuntimeException(sprintf('Invalid entry for extension "%s" in %s.', $extension, $file));
                }

                if (isset($extensions[$extension]) && $file !== 'overrides.yaml') {
                    $previous = $extensions[$extension]['source'];
                    throw new \RuntimeException(sprintf(
                        'Duplicate extension "%s" in %s (already defined in %s). Use overrides.yaml to replace intentionally.',
                        $extension,
                        $file,
                        $previous,
                    ));
                }

                $extensions[$extension] = [
                    'icon' => $icon,
                    'category' => $category,
                    'source' => $file,
                ];
            }
        }

        $this->extensions = $extensions;
    }

    /**
     * @brief Load filename map from YAML.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function loadFilenames(): void
    {
        if ($this->filenames !== null) {
            return;
        }

        $path = $this->mappingsDir.'/filenames.yaml';
        if (!is_readable($path)) {
            $this->filenames = [];

            return;
        }

        $data = Yaml::parseFile($path);
        $entries = $data['filenames'] ?? [];
        if (!\is_array($entries)) {
            throw new \RuntimeException('Invalid filenames.yaml: filenames must be a map.');
        }

        $filenames = [];
        foreach ($entries as $filename => $entry) {
            if (!\is_string($filename) || !\is_array($entry)) {
                continue;
            }
            $icon = $entry['icon'] ?? null;
            $category = $entry['category'] ?? null;
            if (!\is_string($icon) || !\is_string($category)) {
                throw new \RuntimeException(sprintf('Invalid filename entry for "%s".', $filename));
            }
            $filenames[$filename] = ['icon' => $icon, 'category' => $category];
        }

        $this->filenames = $filenames;
    }

    /**
     * @brief Get extension mapping entry.
     *
     * @param string $extension Normalized extension.
     * @return array{icon: string, category: string, source?: string}|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function getExtensionEntry(string $extension): ?array
    {
        $this->loadExtensions();

        return $this->extensions[$extension] ?? null;
    }
}
