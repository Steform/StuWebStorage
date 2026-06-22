<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static JavaScript sentinel tests for files section accordion persistence.
 */
class AccordionPersistenceJsTest extends TestCase
{
    /**
     * @brief Read a repository file and return raw source.
     * @param string $relativePath Repo-relative file path.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Ensure accordion persistence keys and helper functions exist in files-space.js.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testFilesSpaceDeclaresAccordionPersistencePrimitives(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("files.section.my_files.expanded", $source);
        self::assertStringContainsString("files.section.shared_for_me.expanded", $source);
        self::assertStringContainsString('function getSectionStorageKey(sectionId)', $source);
        self::assertStringContainsString('function readPersistedSectionExpanded(storageKey, defaultExpanded)', $source);
        self::assertStringContainsString('function writePersistedSectionExpanded(storageKey, expanded)', $source);
        self::assertStringContainsString('sectionStateFallbackStore', $source);
    }

    /**
     * @brief Ensure accordion state is re-applied on initial load and after partial fetch replacement.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testFilesSpaceRehydratesAccordionStateAfterPartialRefresh(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('function initFilesSectionAccordions(root)', $source);
        self::assertStringContainsString('initFilesSectionAccordions(document);', $source);
        self::assertStringContainsString('initFilesSectionAccordions(liveRegion);', $source);
        self::assertStringContainsString("sectionEl.addEventListener('shown.bs.collapse'", $source);
        self::assertStringContainsString("sectionEl.addEventListener('hidden.bs.collapse'", $source);
    }
}
