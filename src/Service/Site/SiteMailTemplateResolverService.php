<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Service\Locale\LocaleConfigurationService;
use App\Service\RichText\RichHtmlSanitizer;
use App\Site\SiteMailTemplatesContract;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @brief Resolve mail template content for outbound notifications with locale and YAML fallbacks.
 */
final class SiteMailTemplateResolverService
{
    /**
     * @brief Build mail template resolver service.
     *
     * @param SiteMailTemplateDefaultContentService $defaultContentService Default template content builder.
     * @param RichHtmlSanitizer $richHtmlSanitizer HTML sanitizer for legacy stored blocks.
     * @param TranslatorInterface $translator Translation fallback service.
     * @param LocaleConfigurationService $localeConfigurationService Active locale configuration.
     * @param string $envFromEmail Fallback sender email from environment.
     * @param list<string> $supportedLocales Supported locale codes.
     * @param string $fallbackLocale Secondary locale fallback.
     * @return void
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function __construct(
        private readonly SiteMailTemplateDefaultContentService $defaultContentService,
        private readonly RichHtmlSanitizer $richHtmlSanitizer,
        private readonly TranslatorInterface $translator,
        private readonly LocaleConfigurationService $localeConfigurationService,
        private readonly string $envFromEmail = 'no-reply@localhost',
        private readonly string $envToEmail = '',
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no'],
        private readonly string $fallbackLocale = 'fr',
    ) {
    }

    /**
     * @brief Resolve sender, subject, blocks and labels for one outbound mail.
     *
     * @param string $type Template type key.
     * @param string|null $locale Preferred recipient locale.
     * @param array<string, string> $subjectParameters Optional subject translation parameters.
     * @return array{
     *     locale: string,
     *     fromEmail: string,
     *     fromName: string,
     *     toEmail: string|null,
     *     subject: string,
     *     blocks: array<string, string>,
     *     labels: array<string, string>
     * }
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function resolve(string $type, ?string $locale = null, array $subjectParameters = []): array
    {
        if (!in_array($type, SiteMailTemplatesContract::TEMPLATE_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown mail template type: '.$type);
        }

        $resolvedLocale = $this->resolveLocaleChain($locale);
        $stored = SiteMailTemplatesContract::normalize(null);
        $typeData = $stored[$type] ?? SiteMailTemplatesContract::normalize(null)[$type];
        $localeRow = $this->resolveLocaleRow($typeData['locales'], $type, $resolvedLocale);

        $fromEmail = $typeData['fromEmail'] ?? null;
        if ($fromEmail === null || !SiteMailTemplatesContract::isValidFromEmail($fromEmail)) {
            $fromEmail = $this->resolveEnvFromEmail();
        }

        $fromName = trim((string) ($typeData['fromName'] ?? ''));
        if ($fromName === '') {
            $fromName = $this->resolveDefaultFromName($type, $resolvedLocale);
        }

        $toEmail = null;
        if (SiteMailTemplatesContract::supportsToEmail($type)) {
            $storedToEmail = $typeData['toEmail'] ?? null;
            if (is_string($storedToEmail) && $storedToEmail !== '' && SiteMailTemplatesContract::isValidFromEmail($storedToEmail)) {
                $toEmail = $storedToEmail;
            } else {
                $toEmail = $this->resolveEnvToEmail();
            }
        }

        $subject = trim($localeRow['subject']);
        if ($subject === '') {
            $subject = $this->resolveDefaultSubject($type, $resolvedLocale, $subjectParameters);
        } elseif ($subjectParameters !== []) {
            $subject = strtr($subject, $subjectParameters);
        }

        $blocks = [];
        foreach (SiteMailTemplatesContract::blockKeysForType($type) as $blockKey) {
            $storedBlock = trim($localeRow['blocks'][$blockKey] ?? '');
            if ($storedBlock !== '') {
                $blocks[$blockKey] = $this->richHtmlSanitizer->sanitize($storedBlock);
                if ($subjectParameters !== []) {
                    $blocks[$blockKey] = strtr($blocks[$blockKey], $subjectParameters);
                }
                continue;
            }
            $defaultBlock = $this->defaultContentService->buildLocaleDefaults($type, $resolvedLocale)['blocks'][$blockKey] ?? '';
            $blocks[$blockKey] = $subjectParameters !== [] ? strtr($defaultBlock, $subjectParameters) : $defaultBlock;
        }

        $labels = [];
        foreach (SiteMailTemplatesContract::labelKeysForType($type) as $labelKey) {
            $storedLabel = trim($localeRow['labels'][$labelKey] ?? '');
            if ($storedLabel !== '') {
                $labels[$labelKey] = $storedLabel;
                continue;
            }
            $labels[$labelKey] = $this->defaultContentService->buildLocaleDefaults($type, $resolvedLocale)['labels'][$labelKey] ?? '';
        }

        return [
            'locale' => $resolvedLocale,
            'fromEmail' => $fromEmail,
            'fromName' => $fromName,
            'toEmail' => $toEmail,
            'subject' => $subject,
            'blocks' => $blocks,
            'labels' => $labels,
        ];
    }

    /**
     * @brief Strip HTML from a resolved block for plain-text templates.
     *
     * @param string $html Sanitized HTML block.
     * @return string Plain text content.
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function toPlainText(string $html): string
    {
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = str_replace("\xc2\xa0", ' ', $plain);

        return preg_replace('/\s+/u', ' ', $plain) ?? $plain;
    }

    /**
     * @brief Resolve locale fallback chain ending on a supported locale code.
     *
     * @param string|null $locale Preferred locale candidate.
     * @return string Resolved locale code.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function resolveLocaleChain(?string $locale): string
    {
        $configuration = $this->localeConfigurationService->getConfiguration();
        $activeLocales = is_array($configuration['activeLocales'] ?? null) ? $configuration['activeLocales'] : $this->supportedLocales;
        $defaultLocale = is_string($configuration['defaultLocale'] ?? null) ? $configuration['defaultLocale'] : ($activeLocales[0] ?? 'en');

        $candidates = [
            strtolower(trim((string) $locale)),
            strtolower(trim($defaultLocale)),
            strtolower(trim($this->fallbackLocale)),
            'fr',
            'en',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && in_array($candidate, $activeLocales, true)) {
                return $candidate;
            }
        }

        return $activeLocales[0] ?? $this->supportedLocales[0] ?? 'en';
    }

    /**
     * @brief Resolve locale row using locale chain against stored templates.
     *
     * @param array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}> $locales Stored locale rows.
     * @param string $type Template type key.
     * @param string $resolvedLocale Primary resolved locale.
     * @return array{subject: string, blocks: array<string, string>, labels: array<string, string>}
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function resolveLocaleRow(array $locales, string $type, string $resolvedLocale): array
    {
        $candidates = array_unique([
            $resolvedLocale,
            strtolower(trim($this->fallbackLocale)),
            'fr',
            'en',
        ]);

        foreach ($candidates as $candidate) {
            if (isset($locales[$candidate]) && is_array($locales[$candidate])) {
                return $locales[$candidate];
            }
        }

        return $this->defaultContentService->buildLocaleDefaults($type, $resolvedLocale);
    }

    /**
     * @brief Resolve default subject from translation keys.
     *
     * @param string $type Template type key.
     * @param string $locale Locale code.
     * @param array<string, string> $subjectParameters Subject translation parameters.
     * @return string Resolved subject line.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function resolveDefaultSubject(string $type, string $locale, array $subjectParameters): string
    {
        $key = match ($type) {
            SiteMailTemplatesContract::TYPE_TOTP => 'mail.totp.subject',
            SiteMailTemplatesContract::TYPE_INVITATION => 'mail.invite.subject',
            SiteMailTemplatesContract::TYPE_PASSWORD_RESET => 'mail.password_reset.subject',
            default => '',
        };
        if ($key === '') {
            return '';
        }

        return $this->translator->trans($key, $subjectParameters, 'messages', $locale);
    }

    /**
     * @brief Resolve default sender display name from translation keys.
     *
     * @param string $type Template type key.
     * @param string $locale Locale code.
     * @return string Resolved sender display name.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function resolveDefaultFromName(string $type, string $locale): string
    {
        $key = match ($type) {
            SiteMailTemplatesContract::TYPE_TOTP => 'mail.totp.brand',
            SiteMailTemplatesContract::TYPE_INVITATION => 'mail.invite.brand',
            SiteMailTemplatesContract::TYPE_PASSWORD_RESET => 'mail.password_reset.brand',
            default => 'mail.totp.brand',
        };

        return $this->translator->trans($key, [], 'messages', $locale);
    }

    /**
     * @brief Resolve environment fallback sender email.
     *
     * @param void No input parameter.
     * @return string Valid sender email.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function resolveEnvFromEmail(): string
    {
        $candidate = strtolower(trim($this->envFromEmail));
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
            return $candidate;
        }

        return 'no-reply@localhost';
    }

    /**
     * @brief Resolve environment fallback recipient email for recruiter visit notifications.
     *
     * @param void No input parameter.
     * @return string|null Valid recipient email or null when unset.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function resolveEnvToEmail(): ?string
    {
        $candidate = strtolower(trim($this->envToEmail));
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
            return $candidate;
        }

        return null;
    }
}
