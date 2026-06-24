<?php

declare(strict_types=1);

namespace App\Twig;

use App\File\FileExtensionIconResolver;
use App\File\FileIconDescriptor;
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
     * @brief Resolve icon metadata for a file extension.
     *
     * @param string $extension Raw file extension.
     * @return FileIconDescriptor
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolveDescriptor(string $extension): FileIconDescriptor
    {
        return $this->iconResolver->resolve($extension);
    }

    /**
     * @brief Render UX icon markup for a file extension.
     *
     * @param string $extension Raw file extension.
     * @param array<string, mixed> $attributes Optional SVG attributes.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function renderIcon(string $extension, array $attributes = []): string
    {
        $descriptor = $this->iconResolver->resolve($extension);

        return $this->iconRenderer->renderIcon($descriptor->iconName, $attributes);
    }
}
