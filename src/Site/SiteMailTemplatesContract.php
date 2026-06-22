<?php

declare(strict_types=1);

namespace App\Site;

/**
 * @brief Site-wide mail template contract for outbound notification types.
 *
 * @date 2026-06-16
 * @author Stephane H.
 */
final class SiteMailTemplatesContract
{
    public const TYPE_TOTP = 'totp';

    public const TYPE_INVITATION = 'invitation';

    /** @var list<string> */
    public const TEMPLATE_TYPES = [
        self::TYPE_TOTP,
        self::TYPE_INVITATION,
    ];

    /** @var list<string> */
    public const TOTP_BLOCKS = ['title', 'intro', 'expiry_hint', 'security_hint', 'footer'];

    /** @var list<string> */
    public const INVITATION_BLOCKS = ['title', 'intro', 'expiry_hint', 'security_hint', 'footer'];

    /** @var list<string> */
    public const TOTP_LABELS = ['brand', 'code_label'];

    /** @var list<string> */
    public const INVITATION_LABELS = ['cta'];

    /**
     * @brief Whether a template type supports a customizable recipient email.
     *
     * @param string $type Template type key.
     * @return bool
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function supportsToEmail(string $type): bool
    {
        return false;
    }

    /**
     * @brief Return rich-text block keys for a template type.
     *
     * @param string $type Template type key.
     * @return list<string>
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function blockKeysForType(string $type): array
    {
        return match ($type) {
            self::TYPE_TOTP => self::TOTP_BLOCKS,
            self::TYPE_INVITATION => self::INVITATION_BLOCKS,
            default => [],
        };
    }

    /**
     * @brief Return short label keys for a template type.
     *
     * @param string $type Template type key.
     * @return list<string>
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function labelKeysForType(string $type): array
    {
        return match ($type) {
            self::TYPE_TOTP => self::TOTP_LABELS,
            self::TYPE_INVITATION => self::INVITATION_LABELS,
            default => [],
        };
    }

    /**
     * @brief Normalize persisted mail templates map with empty defaults per type.
     *
     * @param mixed $raw Raw JSON-decoded value or null.
     * @return array<string, array{fromEmail: string|null, fromName: string|null, toEmail: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}>
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function normalize(mixed $raw): array
    {
        $normalized = [];
        foreach (self::TEMPLATE_TYPES as $type) {
            $normalized[$type] = self::normalizeType(is_array($raw) ? ($raw[$type] ?? null) : null, $type);
        }

        return $normalized;
    }

    /**
     * @brief Encode normalized map for JSON persistence.
     *
     * @param array<string, array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}> $templates Normalized templates map.
     * @return string|null JSON string or null when no custom templates are stored.
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function encodeForStorage(array $templates): ?string
    {
        $payload = [];
        foreach (self::TEMPLATE_TYPES as $type) {
            $typeData = self::normalizeType($templates[$type] ?? null, $type);
            if (!self::typeHasCustomData($typeData)) {
                continue;
            }
            $payload[$type] = self::serializeTypeForStorage($typeData);
        }

        if ($payload === []) {
            return null;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @brief Decode JSON column value into normalized map.
     *
     * @param string|null $json Stored JSON mail templates payload.
     * @return array<string, array{fromEmail: string|null, fromName: string|null, toEmail: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}>
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function decodeFromStorage(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return self::normalize(null);
        }

        $decoded = json_decode($json, true);

        return self::normalize(is_array($decoded) ? $decoded : null);
    }

    /**
     * @brief Merge admin POST mail template fields into normalized map.
     *
     * @param array<string, array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}> $existing Existing normalized templates map.
     * @param array<string, mixed> $submitted Raw `mail_templates` request map.
     * @param list<string> $activeLocales Allowed locale codes for locale rows.
     * @return array<string, array{fromEmail: string|null, fromName: string|null, toEmail: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}>
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function mergeSubmitted(array $existing, array $submitted, array $activeLocales): array
    {
        $merged = $existing;
        foreach (self::TEMPLATE_TYPES as $type) {
            if (!isset($submitted[$type]) || !is_array($submitted[$type])) {
                continue;
            }
            $typeSubmitted = $submitted[$type];
            $typeExisting = $merged[$type] ?? self::emptyType($type);

            $fromEmail = self::sanitizeEmail($typeSubmitted['from_email'] ?? $typeExisting['fromEmail']);
            if ($fromEmail !== null) {
                $typeExisting['fromEmail'] = $fromEmail;
            } elseif (array_key_exists('from_email', $typeSubmitted) && trim((string) $typeSubmitted['from_email']) === '') {
                $typeExisting['fromEmail'] = null;
            }

            $fromName = self::sanitizeShortText($typeSubmitted['from_name'] ?? null, 120);
            if ($fromName !== '') {
                $typeExisting['fromName'] = $fromName;
            } elseif (array_key_exists('from_name', $typeSubmitted) && trim((string) $typeSubmitted['from_name']) === '') {
                $typeExisting['fromName'] = null;
            }

            if (self::supportsToEmail($type)) {
                $toEmail = self::sanitizeEmail($typeSubmitted['to_email'] ?? $typeExisting['toEmail']);
                if ($toEmail !== null) {
                    $typeExisting['toEmail'] = $toEmail;
                } elseif (array_key_exists('to_email', $typeSubmitted) && trim((string) $typeSubmitted['to_email']) === '') {
                    $typeExisting['toEmail'] = null;
                }
            }

            $localesSubmitted = is_array($typeSubmitted['locales'] ?? null) ? $typeSubmitted['locales'] : [];
            foreach ($activeLocales as $locale) {
                if (!isset($localesSubmitted[$locale]) || !is_array($localesSubmitted[$locale])) {
                    continue;
                }
                $localeSubmitted = $localesSubmitted[$locale];
                $localeExisting = $typeExisting['locales'][$locale] ?? self::emptyLocale($type);

                $subject = self::sanitizeShortText($localeSubmitted['subject'] ?? null, 255);
                if ($subject !== '') {
                    $localeExisting['subject'] = $subject;
                }

                $blocksSubmitted = is_array($localeSubmitted['blocks'] ?? null) ? $localeSubmitted['blocks'] : [];
                foreach (self::blockKeysForType($type) as $blockKey) {
                    if (!array_key_exists($blockKey, $blocksSubmitted)) {
                        continue;
                    }
                    $localeExisting['blocks'][$blockKey] = trim((string) $blocksSubmitted[$blockKey]);
                }

                $labelsSubmitted = is_array($localeSubmitted['labels'] ?? null) ? $localeSubmitted['labels'] : [];
                foreach (self::labelKeysForType($type) as $labelKey) {
                    if (!array_key_exists($labelKey, $labelsSubmitted)) {
                        continue;
                    }
                    $labelValue = self::sanitizeShortText($labelsSubmitted[$labelKey] ?? null, 255);
                    if ($labelValue !== '') {
                        $localeExisting['labels'][$labelKey] = $labelValue;
                    }
                }

                $typeExisting['locales'][$locale] = $localeExisting;
            }

            $merged[$type] = $typeExisting;
        }

        return $merged;
    }

    /**
     * @brief Validate from email when explicitly set.
     *
     * @param string|null $email Candidate from email.
     * @return bool True when empty or valid email format.
     * @date 2026-06-16
     * @author Stephane H.
     */
    public static function isValidFromEmail(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return true;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @brief Normalize one template type payload.
     *
     * @param mixed $raw Raw type payload.
     * @param string $type Template type key.
     * @return array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function normalizeType(mixed $raw, string $type): array
    {
        $typeData = self::emptyType($type);
        if (!is_array($raw)) {
            return $typeData;
        }

        $typeData['fromEmail'] = self::sanitizeEmail($raw['fromEmail'] ?? $raw['from_email'] ?? null);
        $fromName = self::sanitizeShortText($raw['fromName'] ?? $raw['from_name'] ?? null, 120);
        $typeData['fromName'] = $fromName !== '' ? $fromName : null;
        if (self::supportsToEmail($type)) {
            $typeData['toEmail'] = self::sanitizeEmail($raw['toEmail'] ?? $raw['to_email'] ?? null);
        }

        $localesRaw = is_array($raw['locales'] ?? null) ? $raw['locales'] : [];
        foreach ($localesRaw as $locale => $localeRaw) {
            if (!is_string($locale) || !is_array($localeRaw)) {
                continue;
            }
            $normalizedLocale = strtolower(trim($locale));
            if ($normalizedLocale === '') {
                continue;
            }
            $typeData['locales'][$normalizedLocale] = self::normalizeLocale($localeRaw, $type);
        }

        return $typeData;
    }

    /**
     * @brief Normalize one locale row for a template type.
     *
     * @param array<string, mixed> $raw Locale payload.
     * @param string $type Template type key.
     * @return array{subject: string, blocks: array<string, string>, labels: array<string, string>}
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function normalizeLocale(array $raw, string $type): array
    {
        $locale = self::emptyLocale($type);
        $locale['subject'] = self::sanitizeShortText($raw['subject'] ?? null, 255);

        $blocksRaw = is_array($raw['blocks'] ?? null) ? $raw['blocks'] : [];
        foreach (self::blockKeysForType($type) as $blockKey) {
            $locale['blocks'][$blockKey] = trim((string) ($blocksRaw[$blockKey] ?? ''));
        }

        $labelsRaw = is_array($raw['labels'] ?? null) ? $raw['labels'] : [];
        foreach (self::labelKeysForType($type) as $labelKey) {
            $labelValue = self::sanitizeShortText($labelsRaw[$labelKey] ?? null, 255);
            if ($labelValue !== '') {
                $locale['labels'][$labelKey] = $labelValue;
            }
        }

        return $locale;
    }

    /**
     * @brief Build empty type structure with default block/label keys.
     *
     * @param string $type Template type key.
     * @return array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>}
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function emptyType(string $type): array
    {
        return [
            'fromEmail' => null,
            'fromName' => null,
            'toEmail' => null,
            'locales' => [],
        ];
    }

    /**
     * @brief Build empty locale row for a template type.
     *
     * @param string $type Template type key.
     * @return array{subject: string, blocks: array<string, string>, labels: array<string, string>}
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function emptyLocale(string $type): array
    {
        $blocks = [];
        foreach (self::blockKeysForType($type) as $blockKey) {
            $blocks[$blockKey] = '';
        }

        return [
            'subject' => '',
            'blocks' => $blocks,
            'labels' => [],
        ];
    }

    /**
     * @brief Whether a normalized type row contains any persisted custom data.
     *
     * @param array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>} $typeData Normalized type row.
     * @return bool
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function typeHasCustomData(array $typeData): bool
    {
        if ($typeData['fromEmail'] !== null || $typeData['fromName'] !== null || $typeData['toEmail'] !== null) {
            return true;
        }

        foreach ($typeData['locales'] as $localeRow) {
            if ($localeRow['subject'] !== '') {
                return true;
            }
            foreach ($localeRow['blocks'] as $blockValue) {
                if (trim($blockValue) !== '') {
                    return true;
                }
            }
            foreach ($localeRow['labels'] as $labelValue) {
                if (trim($labelValue) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @brief Serialize normalized type row for JSON storage (omit empty values).
     *
     * @param array{fromEmail: string|null, fromName: string|null, locales: array<string, array{subject: string, blocks: array<string, string>, labels: array<string, string>}>} $typeData Normalized type row.
     * @return array<string, mixed>
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function serializeTypeForStorage(array $typeData): array
    {
        $payload = [];
        if ($typeData['fromEmail'] !== null) {
            $payload['fromEmail'] = $typeData['fromEmail'];
        }
        if ($typeData['fromName'] !== null) {
            $payload['fromName'] = $typeData['fromName'];
        }
        if ($typeData['toEmail'] !== null) {
            $payload['toEmail'] = $typeData['toEmail'];
        }

        $localesPayload = [];
        foreach ($typeData['locales'] as $locale => $localeRow) {
            $localePayload = [];
            if ($localeRow['subject'] !== '') {
                $localePayload['subject'] = $localeRow['subject'];
            }

            $blocksPayload = [];
            foreach ($localeRow['blocks'] as $blockKey => $blockValue) {
                if (trim($blockValue) !== '') {
                    $blocksPayload[$blockKey] = $blockValue;
                }
            }
            if ($blocksPayload !== []) {
                $localePayload['blocks'] = $blocksPayload;
            }

            if ($localeRow['labels'] !== []) {
                $localePayload['labels'] = $localeRow['labels'];
            }

            if ($localePayload !== []) {
                $localesPayload[$locale] = $localePayload;
            }
        }

        if ($localesPayload !== []) {
            $payload['locales'] = $localesPayload;
        }

        return $payload;
    }

    /**
     * @brief Sanitize optional email address.
     *
     * @param mixed $raw Raw email value.
     * @return string|null Lowercased trimmed email or null when empty/invalid.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function sanitizeEmail(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $normalized = strtolower(trim($raw));
        if ($normalized === '') {
            return null;
        }
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    /**
     * @brief Sanitize short plain-text field.
     *
     * @param mixed $raw Raw text value.
     * @param int $maxLength Maximum allowed length.
     * @return string Trimmed text capped to max length.
     * @date 2026-06-16
     * @author Stephane H.
     */
    private static function sanitizeShortText(mixed $raw, int $maxLength): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }
        if (mb_strlen($trimmed) > $maxLength) {
            return mb_substr($trimmed, 0, $maxLength);
        }

        return $trimmed;
    }
}
