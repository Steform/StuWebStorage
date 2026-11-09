<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contracts for the root runtime-permissions script (EPIC-PERM).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class RuntimePermissionsScriptContractTest extends TestCase
{
    /**
     * @brief Read a repository file relative to the project root.
     * @param string $relativePath Relative path.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief The permissions script exists, documents usage, and lists every runtime tree.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testFixRuntimePermissionsScriptCoversAllRuntimeTrees(): void
    {
        $root = dirname(__DIR__, 3);
        $scriptPath = $root.'/fix-runtime-permissions.sh';
        self::assertFileExists($scriptPath);
        self::assertTrue(is_executable($scriptPath), 'fix-runtime-permissions.sh must be executable');

        $script = $this->readSource('fix-runtime-permissions.sh');
        self::assertStringStartsWith('#!/usr/bin/env bash', $script);
        self::assertStringContainsString('--check', $script);
        self::assertStringContainsString('--web-user', $script);
        self::assertStringContainsString('sudo -u www-data', $script);
        self::assertStringContainsString('2775', $script);

        $requiredDirs = [
            'var/cache',
            'var/log',
            'var/sessions',
            'var/shared',
            'var/chunk_upload',
            'var/tasks',
            'var/extract',
            'var/download_prepare',
            'var/bug-reports',
            'var/config',
        ];
        foreach ($requiredDirs as $dir) {
            self::assertStringContainsString('"'.$dir.'"', $script, 'script must list '.$dir);
        }

        $readme = $this->readSource('README.md');
        self::assertStringContainsString('fix-runtime-permissions.sh', $readme);

        $envExemple = $this->readSource('.env.exemple');
        self::assertStringContainsString('fix-runtime-permissions.sh', $envExemple);

        $gitignore = $this->readSource('.gitignore');
        self::assertStringContainsString('var/extract', $gitignore);
        self::assertStringContainsString('var/bug-reports', $gitignore);
        self::assertStringContainsString('var/tasks', $gitignore);

        self::assertFileExists($root.'/docs/epics/EPIC-PERM-droits-runtime.md');
        self::assertFileExists($root.'/var/extract/.gitkeep');
        self::assertFileExists($root.'/var/bug-reports/.gitkeep');
        self::assertFileExists($root.'/var/tasks/.gitkeep');
    }
}
