<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\File\FileIconMappingProvider;
use PHPUnit\Framework\TestCase;

/**
 * @brief Contract between YAML mappings and vscode-icons on Iconify.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileIconMappingIconifyContractTest extends TestCase
{
    private const ICONIFY_INDEX_URL = 'https://raw.githubusercontent.com/iconify/icon-sets/master/json/vscode-icons.json';

    /**
     * @brief Every referenced icon suffix exists in the vscode-icons Iconify set.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testReferencedIconsExistOnIconify(): void
    {
        $root = dirname(__DIR__, 3);
        $provider = new FileIconMappingProvider(
            $root.'/config/icons/mappings',
            $root.'/config/icons/categories.yaml',
        );

        $iconifySet = $this->loadIconifyIconSet();
        $missing = [];
        foreach ($provider->listAllIconSuffixes() as $suffix) {
            if (!isset($iconifySet[$suffix])) {
                $missing[] = $suffix;
            }
        }

        self::assertSame([], $missing, 'Missing Iconify icons: '.implode(', ', $missing));
    }

    /**
     * @brief Download vscode-icons index from Iconify mirror.
     *
     * @param void No input parameter.
     * @return array<string, true>
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function loadIconifyIconSet(): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'header' => "User-Agent: StuWebStorage-phpunit\r\n",
            ],
        ]);

        $json = file_get_contents(self::ICONIFY_INDEX_URL, false, $context);
        self::assertNotFalse($json, 'Could not download vscode-icons index from Iconify.');

        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('icons', $data);

        $set = [];
        foreach (array_keys($data['icons']) as $iconName) {
            if (\is_string($iconName)) {
                $set[$iconName] = true;
            }
        }

        return $set;
    }
}
