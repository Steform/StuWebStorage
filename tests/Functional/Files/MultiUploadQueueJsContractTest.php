<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contracts for multi-file upload queue JavaScript.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class MultiUploadQueueJsContractTest extends TestCase
{
    /**
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function readJs(): string
    {
        $path = dirname(__DIR__, 3).'/public/js/files-space.js';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testFilesSpaceDeclaresMultiUploadQueueFunctions(): void
    {
        $src = $this->readJs();

        self::assertStringContainsString('function uploadSingleFile', $src);
        self::assertStringContainsString('function uploadFileQueue', $src);
        self::assertStringContainsString('function assignFilesToInput', $src);
        self::assertStringContainsString('function buildUploadEntryFromFile', $src);
        self::assertStringContainsString('function collectUploadEntriesFromDataTransfer', $src);
        self::assertStringContainsString('function traverseFileTreeEntry', $src);
        self::assertStringContainsString('webkitRelativePath', $src);
        self::assertStringContainsString("fdSession.append('relative_path'", $src);
        self::assertStringContainsString('function shouldFilterSystemUploadPath', $src);
        self::assertStringContainsString('files-upload-queue', $src);
        self::assertStringContainsString('function resolveQueueVisibleIndices', $src);
        self::assertStringContainsString('queueListExpanded', $src);
        self::assertStringContainsString('msgMultiQueueShowAll', $src);
    }
}
