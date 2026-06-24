<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Site\SiteMailTemplatesContract;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @brief Builds default mail template admin values from existing translation keys.
 */
final class SiteMailTemplateDefaultContentService
{
    /**
     * @brief Build default content service.
     *
     * @param TranslatorInterface $translator Translation service.
     * @return void
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @brief Build full normalized templates map seeded from translation defaults.
     *
     * @param list<string> $activeLocales Active locale codes.
     * @return array<string, array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}>
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function buildDefaultsForLocales(array $activeLocales): array
    {
        $templates = SiteMailTemplatesContract::normalize(null);
        foreach (SiteMailTemplatesContract::TEMPLATE_TYPES as $type) {
            foreach ($activeLocales as $locale) {
                $templates[$type]['locales'][$locale] = $this->buildLocaleDefaults($type, $locale);
            }
        }

        return $templates;
    }

    /**
     * @brief Build default locale row for one template type.
     *
     * @param string $type Template type key.
     * @param string $locale Locale code.
     * @return array{subject: string, blocks: array<string, string>, labels: array<string, string>}
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function buildLocaleDefaults(string $type, string $locale): array
    {
        return match ($type) {
            SiteMailTemplatesContract::TYPE_TOTP => [
                'subject' => $this->trans('mail.totp.subject', $locale),
                'blocks' => [
                    'title' => $this->wrapHeading($this->trans('mail.totp.title', $locale), 2),
                    'intro' => $this->wrapParagraph($this->trans('mail.totp.intro', $locale)),
                    'expiry_hint' => $this->wrapParagraph($this->trans('mail.totp.expiry_hint', $locale)),
                    'security_hint' => $this->wrapParagraph($this->trans('mail.totp.security_hint', $locale)),
                    'footer' => $this->wrapParagraph($this->trans('mail.totp.footer', $locale)),
                ],
                'labels' => [
                    'brand' => $this->trans('mail.totp.brand', $locale),
                    'code_label' => $this->trans('mail.totp.code_label', $locale),
                ],
            ],
            SiteMailTemplatesContract::TYPE_INVITATION => [
                'subject' => $this->trans('mail.invite.subject', $locale),
                'blocks' => [
                    'title' => $this->wrapHeading($this->trans('mail.invite.title', $locale), 2),
                    'intro' => $this->wrapParagraph($this->trans('mail.invite.intro', $locale)),
                    'expiry_hint' => $this->wrapParagraph($this->trans('mail.invite.expiry_hint', $locale)),
                    'security_hint' => $this->wrapParagraph($this->trans('mail.invite.security_hint', $locale)),
                    'footer' => $this->wrapParagraph($this->trans('mail.invite.footer', $locale)),
                ],
                'labels' => [
                    'brand' => $this->trans('mail.invite.brand', $locale),
                    'cta' => $this->trans('mail.invite.cta', $locale),
                ],
            ],
            SiteMailTemplatesContract::TYPE_PASSWORD_RESET => [
                'subject' => $this->trans('mail.password_reset.subject', $locale),
                'blocks' => [
                    'title' => $this->wrapHeading($this->trans('mail.password_reset.title', $locale), 2),
                    'intro' => $this->wrapParagraph($this->trans('mail.password_reset.intro', $locale)),
                    'expiry_hint' => $this->wrapParagraph($this->trans('mail.password_reset.expiry_hint', $locale)),
                    'security_hint' => $this->wrapParagraph($this->trans('mail.password_reset.security_hint', $locale)),
                    'footer' => $this->wrapParagraph($this->trans('mail.password_reset.footer', $locale)),
                ],
                'labels' => [
                    'brand' => $this->trans('mail.password_reset.brand', $locale),
                    'cta' => $this->trans('mail.password_reset.cta', $locale),
                ],
            ],
            default => [
                'subject' => '',
                'blocks' => [],
                'labels' => [],
            ],
        };
    }

    /**
     * @brief Reset one template type to translation defaults for active locales.
     *
     * @param string $type Template type key.
     * @param list<string> $activeLocales Active locale codes.
     * @return array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function buildTypeDefaults(string $type, array $activeLocales): array
    {
        $typeData = [
            'fromEmail' => null,
            'fromName' => null,
            'locales' => [],
        ];
        foreach ($activeLocales as $locale) {
            $typeData['locales'][$locale] = $this->buildLocaleDefaults($type, $locale);
        }

        return $typeData;
    }

    /**
     * @brief Translate a mail default key for a locale.
     *
     * @param string $key Translation key.
     * @param string $locale Locale code.
     * @param array<string, string> $parameters Optional translation parameters.
     * @return string Translated string.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'messages', $locale);
    }

    /**
     * @brief Wrap plain text in a paragraph for CKEditor seed content.
     *
     * @param string $text Plain text.
     * @return string HTML paragraph.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function wrapParagraph(string $text): string
    {
        return '<p>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
    }

    /**
     * @brief Wrap plain text in a heading for CKEditor seed content.
     *
     * @param string $text Plain text.
     * @param int $level Heading level (2-6).
     * @return string HTML heading.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private function wrapHeading(string $text, int $level): string
    {
        $level = max(2, min(6, $level));
        $tag = 'h'.$level;

        return '<'.$tag.'>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</'.$tag.'>';
    }
}
