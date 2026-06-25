<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contracts for files UI preference API and frontend wiring.
 * @author Stephane H.
 * @date 2026-05-03
 */
final class UiPreferenceRoutesContractTest extends TestCase
{
    /**
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testControllerDeclaresFilesUiPreferenceApiEndpoints(): void
    {
        $source = $this->readSource('src/Controller/FilesUiPreferenceController.php');

        self::assertStringContainsString("#[Route('/api/ui-preferences/files', name: 'files_ui_preferences_get', methods: ['GET'])]", $source);
        self::assertStringContainsString("#[Route('/api/ui-preferences/files', name: 'files_ui_preferences_save', methods: ['POST'])]", $source);
        self::assertStringContainsString("new CsrfToken(self::CSRF_FILES_UI_PREFERENCES", $source);
        self::assertStringContainsString('function getFilesPreferences(Request $request): JsonResponse', $source);
        self::assertStringContainsString('function saveFilesPreferences(Request $request): JsonResponse', $source);
    }

    /**
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testFilesTemplateExposesUiPreferenceApiDataAttributes(): void
    {
        $source = $this->readSource('templates/files/index.html.twig');

        self::assertStringContainsString("data-files-ui-pref-get-url=\"{{ path('files_ui_preferences_get') }}\"", $source);
        self::assertStringContainsString("data-files-ui-pref-save-url=\"{{ path('files_ui_preferences_save') }}\"", $source);
        self::assertStringContainsString("data-files-ui-pref-csrf=\"{{ csrf_token('files_ui_preferences') }}\"", $source);
        self::assertStringContainsString("data-files-ui-pref-authenticated=\"{{ app.user ? '1' : '0' }}\"", $source);
        self::assertStringContainsString("asset('js/files-ui-preferences.js')", $source);
    }

    /**
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testFilesUiPreferenceScriptDeclaresLegacyMigrationAndDevicePersistence(): void
    {
        $source = $this->readSource('public/js/files-ui-preferences.js');

        self::assertStringContainsString("var PREF_STORAGE_KEY = 'files.ui.preferences.v1';", $source);
        self::assertStringContainsString("var DEVICE_STORAGE_KEY = 'files.ui.device_id.v1';", $source);
        self::assertStringContainsString("var MIGRATION_STORAGE_KEY = 'files.ui.preferences.migrated.v1';", $source);
        self::assertStringContainsString('function getOrCreateDeviceId()', $source);
        self::assertStringContainsString('function migrateLegacyLocalStorageOnce()', $source);
        self::assertStringContainsString('function saveBackendPreferences(deviceId, pref)', $source);
        self::assertStringContainsString('function applyViewAndScopeWithReload(pref)', $source);
        self::assertStringContainsString('filesSortField', $source);
        self::assertStringContainsString('filesSortDirection', $source);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testFilesControllerAppliesPersistedSortWhenQueryIsNeutral(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('applyUserSortPreferenceWhenNeutral', $source);
        self::assertStringContainsString('filesUiPreferenceService->resolveListingSortPreference', $source);
    }
}
