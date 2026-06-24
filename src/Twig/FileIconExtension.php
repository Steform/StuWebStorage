<?php

declare(strict_types=1);

namespace App\Twig;

use App\File\FileExtensionIconResolver;
use App\File\FileIconDescriptor;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\IconRendererInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @brief Twig helpers for file extension UX icons.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileIconExtension extends AbstractExtension
{
    private const FALLBACK_ICON = 'vscode:default-file';

    /**
     * @param FileExtensionIconResolver $iconResolver Extension to icon resolver.
     * @param IconRendererInterface $iconRenderer Symfony UX icon renderer.
     */
    public function __construct(
        private readonly FileExtensionIconResolver $iconResolver,
        private readonly IconRendererInterface $iconRenderer,
    ) {
    }

    /**
     * @brief Register Twig functions.
     *
     * @param void No input parameter.
     * @return array<int, TwigFunction>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('file_icon_descriptor', [$this, 'resolveDescriptor']),
            new TwigFunction('file_ux_icon', [$this, 'renderIcon'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * @brief Resolve icon metadata for a file extension or filename.
     *
     * @param string $extension Raw file extension.
     * @param string|null $filename Optional filename for extensionless files.
     * @return FileIconDescriptor
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolveDescriptor(string $extension, ?string $filename = null): FileIconDescriptor
    {
        if ($filename !== null && $filename !== '') {
            return $this->iconResolver->resolveByFilename($filename, $extension);
        }

        return $this->iconResolver->resolve($extension);
    }

    /**
     * @brief Render UX icon markup for a file extension or filename.
     *
     * @param string $extension Raw file extension.
     * @param array<string, mixed> $attributes Optional SVG attributes.
     * @param string|null $filename Optional filename for extensionless files.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function renderIcon(string $extension, array $attributes = [], ?string $filename = null): string
    {
        $descriptor = $this->resolveDescriptor($extension, $filename);

        try {
            return $this->iconRenderer->renderIcon($descriptor->iconName, $attributes);
        } catch (IconNotFoundException) {
            return $this->iconRenderer->renderIcon(self::FALLBACK_ICON, $attributes);
        }
    }
}
