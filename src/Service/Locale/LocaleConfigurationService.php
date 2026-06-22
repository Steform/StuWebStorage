<?php

namespace App\Service\Locale;

/**
 * Service LocaleConfigurationService.
 */
class LocaleConfigurationService
{
    /**
     * @brief Build locale configuration service.
     * @param array<int, string> $supportedLocales Available locales.
     * @param string $defaultLocale Application default locale.
     * @param string $projectDir Project root directory.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function __construct(
        private readonly array $supportedLocales,
        private readonly string $defaultLocale,
        private readonly string $projectDir
    ) {
    }

    /**
     * @brief Return configured locales with safe fallback.
     * @param void No input parameter.
     * @return array{activeLocales: array<int, string>, defaultLocale: string}
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function getConfiguration(): array
    {
        $fallbackDefaultLocale = $this->normalizeLocale($this->defaultLocale, $this->supportedLocales) ?? $this->supportedLocales[0] ?? 'en';
        $fallbackActiveLocales = $this->supportedLocales;
        $path = $this->getConfigPath();
        if (!is_file($path)) {
            return [
                'activeLocales' => $fallbackActiveLocales,
                'defaultLocale' => $fallbackDefaultLocale,
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'activeLocales' => $fallbackActiveLocales,
                'defaultLocale' => $fallbackDefaultLocale,
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'activeLocales' => $fallbackActiveLocales,
                'defaultLocale' => $fallbackDefaultLocale,
            ];
        }

        $storedActive = is_array($decoded['active_locales'] ?? null) ? $decoded['active_locales'] : [];
        $activeLocales = $this->normalizeLocales($storedActive, $this->supportedLocales);
        if ($activeLocales === []) {
            $activeLocales = $fallbackActiveLocales;
        }

        $storedDefault = is_string($decoded['default_locale'] ?? null) ? $decoded['default_locale'] : '';
        $defaultLocale = $this->normalizeLocale($storedDefault, $activeLocales) ?? $fallbackDefaultLocale;
        if (!in_array($defaultLocale, $activeLocales, true)) {
            $defaultLocale = $activeLocales[0] ?? $fallbackDefaultLocale;
        }

        return [
            'activeLocales' => $activeLocales,
            'defaultLocale' => $defaultLocale,
        ];
    }

    /**
     * @brief Persist active locales and default locale.
     * @param array<int, string> $activeLocales Requested active locales.
     * @param string $defaultLocale Requested default locale.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function saveConfiguration(array $activeLocales, string $defaultLocale): void
    {
        $normalizedActiveLocales = $this->normalizeLocales($activeLocales, $this->supportedLocales);
        if ($normalizedActiveLocales === []) {
            throw new \InvalidArgumentException('At least one active locale is required.');
        }

        $normalizedDefaultLocale = $this->normalizeLocale($defaultLocale, $normalizedActiveLocales);
        if ($normalizedDefaultLocale === null) {
            throw new \InvalidArgumentException('Default locale must be one of the active locales.');
        }

        $payload = [
            'active_locales' => $normalizedActiveLocales,
            'default_locale' => $normalizedDefaultLocale,
        ];

        $directory = dirname($this->getConfigPath());
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($this->getConfigPath(), (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @brief Return full supported locale list.
     * @param void No input parameter.
     * @return array<int, string>
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function getSupportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /**
     * @brief Return locale configuration file path.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function getConfigPath(): string
    {
        return rtrim($this->projectDir, '/').'/var/config/locale_configuration.json';
    }

    /**
     * @brief Normalize locale using supported list.
     * @param string $locale Raw locale value.
     * @param array<int, string> $allowedLocales Allowed locales for current context.
     * @return string|null
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function normalizeLocale(string $locale, array $allowedLocales): ?string
    {
        $normalized = substr(strtolower(trim(str_replace('_', '-', $locale))), 0, 2);
        if (in_array($normalized, ['nb', 'nn'], true)) {
            $normalized = 'no';
        }

        return in_array($normalized, $allowedLocales, true) ? $normalized : null;
    }

    /**
     * @brief Normalize and deduplicate locale list preserving order.
     * @param array<int, mixed> $locales Raw locale values.
     * @param array<int, string> $allowedLocales Allowed locales for current context.
     * @return array<int, string>
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function normalizeLocales(array $locales, array $allowedLocales): array
    {
        $normalizedLocales = [];
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                continue;
            }

            $normalizedLocale = $this->normalizeLocale($locale, $allowedLocales);
            if ($normalizedLocale === null || in_array($normalizedLocale, $normalizedLocales, true)) {
                continue;
            }

            $normalizedLocales[] = $normalizedLocale;
        }

        return $normalizedLocales;
    }
}
