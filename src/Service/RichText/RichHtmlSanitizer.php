<?php

declare(strict_types=1);

namespace App\Service\RichText;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allowlisted HTML fragment sanitizer aligned with CKEditor 5 Classic toolbar output
 * (headings, basic styles, links, lists, inline font color / highlight via `span[style]`).
 */
final class RichHtmlSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $this->sanitizer = new HtmlSanitizer($this->buildConfig());
    }

    private function buildConfig(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig())
            ->withMaxInputLength(100_000)
            ->allowRelativeLinks(true)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel']);

        $classAttrs = ['class'];
        $linkAttrs = ['href', 'rel', 'title', 'class'];

        $config = $config
            ->allowElement('p', $classAttrs)
            ->allowElement('br', [])
            ->allowElement('strong', $classAttrs)
            ->allowElement('b', $classAttrs)
            ->allowElement('em', $classAttrs)
            ->allowElement('i', $classAttrs)
            ->allowElement('u', $classAttrs)
            ->allowElement('span', $classAttrs);

        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $heading) {
            $config = $config->allowElement($heading, $classAttrs);
        }

        return $config
            ->allowElement('ul', $classAttrs)
            ->allowElement('ol', $classAttrs)
            ->allowElement('li', $classAttrs)
            ->allowElement('a', $linkAttrs)
            ->allowAttribute('style', 'span');
    }

    /**
     * @brief Sanitize untrusted HTML using the configured allowlist.
     *
     * @param string $html Raw HTML input.
     * @return string Sanitized HTML safe for controlled rendering contexts.
     * @date 2026-05-14
     * @author Stephane H.
     */
    public function sanitize(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        $sanitized = $this->sanitizer->sanitize($trimmed);

        return $this->hardenSpanColorStyles($sanitized);
    }

    /**
     * @brief Whether sanitized or raw rich HTML has no visible content (empty editor, whitespace, or structural-only tags).
     *
     * @param string $html Sanitized HTML fragment (or raw input; non-empty structural markup without text still counts as empty).
     * @return bool True when the fragment should be treated as unset for default-content fallback.
     * @date 2026-05-16
     * @author Stephane H.
     */
    public function isEffectivelyEmpty(string $html): bool
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return true;
        }

        $plain = trim(html_entity_decode(strip_tags($trimmed), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = str_replace("\xc2\xa0", '', $plain);
        $plain = preg_replace('/\s+/u', '', $plain) ?? '';

        return $plain === '';
    }

    /**
     * @brief Force an uppercase first visible letter in each `h2`/`h3` (user edits may lower-case section titles).
     *
     * @param string $html Sanitized About presentation HTML fragment.
     * @return string HTML with presentation section headings normalized for display and storage.
     * @date 2026-05-16
     * @author Stephane H.
     */
    public function capitalizePresentationHeadingFirstLetters(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '' || !preg_match('/<h[23]\b/i', $trimmed)) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $wrapped = '<?xml encoding="UTF-8"?><div id="__rich_root">'.$trimmed.'</div>';
            if (@$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD) === false) {
                return $html;
            }
            $root = $dom->getElementById('__rich_root');
            if (!$root instanceof DOMElement) {
                return $html;
            }

            $xpath = new DOMXPath($dom);
            $headings = $xpath->query('.//h2|.//h3', $root);
            if ($headings !== false) {
                foreach ($headings as $heading) {
                    if ($heading instanceof DOMElement) {
                        $this->capitalizeFirstVisibleLetterInNode($heading);
                    }
                }
            }

            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }

            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @brief Keep only safe color-related declarations on span[style] after Symfony allowlisting.
     *
     * @param string $html Sanitized HTML fragment.
     * @return string Fragment with span style attributes restricted to color / background-color.
     * @date 2026-05-14
     * @author Stephane H.
     */
    private function hardenSpanColorStyles(string $html): string
    {
        if (!str_contains($html, '<span')) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $wrapped = '<?xml encoding="UTF-8"?><div id="__rich_root">'.$html.'</div>';
            if (@$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD) === false) {
                return $html;
            }
            $root = $dom->getElementById('__rich_root');
            if (!$root instanceof DOMElement) {
                return $html;
            }

            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('.//span[@style]', $root);
            if ($nodes === false) {
                return $html;
            }

            foreach ($nodes as $span) {
                if (!$span instanceof DOMElement) {
                    continue;
                }
                $raw = $span->getAttribute('style');
                $filtered = $this->filterInlineColorStyleAttribute($raw);
                if ($filtered === '') {
                    $span->removeAttribute('style');
                } else {
                    $span->setAttribute('style', $filtered);
                }
            }

            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }

            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @brief Uppercase the first non-whitespace character in the first text node of an element subtree.
     *
     * @param DOMElement $element Heading or wrapper element.
     * @return void
     * @date 2026-05-16
     * @author Stephane H.
     */
    private function capitalizeFirstVisibleLetterInNode(DOMElement $element): void
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText) {
                $data = $child->data;
                if (trim($data) === '') {
                    continue;
                }
                $child->data = self::capitalizeFirstLetterUtf8($data);

                return;
            }
            if ($child instanceof DOMElement) {
                $this->capitalizeFirstVisibleLetterInNode($child);

                return;
            }
        }
    }

    /**
     * @brief Uppercase the first Unicode letter in a string, preserving leading whitespace.
     *
     * @param string $text Raw text segment.
     * @return string Text with first letter uppercased when present.
     * @date 2026-05-16
     * @author Stephane H.
     */
    private static function capitalizeFirstLetterUtf8(string $text): string
    {
        if (preg_match('/^(\s*)(\X)(.*)$/u', $text, $matches) !== 1) {
            return $text;
        }

        return $matches[1].mb_strtoupper($matches[2], 'UTF-8').$matches[3];
    }

    /**
     * @brief Reduce a CSS style attribute to color and background-color declarations without dangerous tokens.
     *
     * @param string $style Raw style attribute value.
     * @return string Filtered declarations joined by "; " or empty string when none remain.
     * @date 2026-05-14
     * @author Stephane H.
     */
    private function filterInlineColorStyleAttribute(string $style): string
    {
        $style = trim($style);
        if ($style === '') {
            return '';
        }

        $allowedProperties = ['color' => true, 'background-color' => true];
        $kept = [];

        foreach (preg_split('/;/', $style) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, ':')) {
                continue;
            }
            $prop = strtolower(trim((string) strstr($chunk, ':', true)));
            $value = trim((string) substr($chunk, strpos($chunk, ':') + 1));
            $value = trim(str_ireplace('!important', '', $value));
            if ($prop === '' || $value === '' || !isset($allowedProperties[$prop])) {
                continue;
            }
            if (preg_match('/url\s*\(|expression\s*\(|behavior\s*:|@import|-moz-binding|[\\\\<>"\']|javascript\s*:/i', $value) === 1) {
                continue;
            }
            $kept[] = $prop.': '.$value;
        }

        return implode('; ', $kept);
    }
}
