<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Sprint contract for the file properties feature: action menu carries a
 *        Properties entry, the dedicated Bootstrap modal skeleton exists and is
 *        wired into the index template, the JS handler + fetch helpers are in
 *        place, the FilesController exposes the GET /files/{id}/properties
 *        route, and every locale ships the new translation keys. Static
 *        inspection avoids booting the Symfony kernel under PowerShell.
 * @author Stephane H.
 * @date 2026-04-27
 */
final class PropertiesEndpointTest extends TestCase
{
    /**
     * @brief Read a repo-relative file and return its raw content.
     * @param string $relativePath Repository-relative path to the file.
     * @return string Raw content, or empty string if unreadable.
     * @author Stephane H.
     * @date 2026-04-27
     */
    private function readFile(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root . DIRECTORY_SEPARATOR . $relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief The per-row action dropdown must expose a Properties entry as the
     *        first item, wired with the `properties` action and the file id.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testActionDropdownExposesPropertiesEntry(): void
    {
        $source = $this->readFile('templates/files/_file_actions_dropdown.html.twig');

        self::assertNotSame('', $source, '_file_actions_dropdown.html.twig must be readable');
        self::assertStringContainsString('data-files-row-action="properties"', $source);
        self::assertStringContainsString('data-files-row-id="{{ file.id }}"', $source);
        self::assertStringContainsString("'files.action.properties'|trans", $source);

        self::assertMatchesRegularExpression(
            '/data-files-row-action="properties"[\s\S]*?data-files-row-action="share-public"/',
            $source,
            'The Properties entry must appear before the public share entry in the dropdown'
        );
    }

    /**
     * @brief The properties modal partial must declare the Bootstrap modal
     *        skeleton with all the data-files-prop hooks consumed by the JS.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testPropertiesModalPartialDefinesRequiredSkeleton(): void
    {
        $source = $this->readFile('templates/files/_properties_modal.html.twig');

        self::assertNotSame('', $source, '_properties_modal.html.twig must be readable');
        self::assertStringContainsString('id="filesPropertiesModal"', $source);
        self::assertStringContainsString('data-files-properties-url-template', $source);
        self::assertStringContainsString("'files.properties.title'|trans", $source);

        $requiredHooks = [
            'data-files-prop="loading"',
            'data-files-prop="error"',
            'data-files-prop="content"',
            'data-files-prop="preview"',
            'data-files-prop="icon-fallback"',
            'data-files-prop="extension-badge"',
            'data-files-prop="name"',
            'data-files-prop="extension"',
            'data-files-prop="size"',
            'data-files-prop="uploaded"',
            'data-files-prop="updated"',
            'data-files-prop-section="public"',
            'data-files-prop="publicStatus"',
            'data-files-prop="publicToken"',
            'data-files-prop="publicValidity"',
            'data-files-prop-section="friends"',
            'data-files-prop-action="edit-public"',
            'data-files-prop-action="edit-friends"',
            'data-files-prop="grants"',
            'data-files-prop="grantsEmpty"',
            'data-files-prop="grantTemplate"',
            'data-files-prop="grantPseudo"',
            'data-files-prop="grantUntil"',
        ];
        foreach ($requiredHooks as $hook) {
            self::assertStringContainsString(
                $hook,
                $source,
                sprintf('Properties modal must expose "%s"', $hook)
            );
        }
    }

    /**
     * @brief The index template must include the properties modal partial so
     *        that it is available regardless of list/grid view.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testIndexTemplateIncludesPropertiesModal(): void
    {
        $source = $this->readFile('templates/files/index.html.twig');

        self::assertNotSame('', $source, 'index.html.twig must be readable');
        self::assertStringContainsString("include 'files/_properties_modal.html.twig'", $source);
    }

    /**
     * @brief The JS bundle must define the populate helper and the delegated
     *        click listener that opens the modal for the clicked row.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testFilesSpaceJsImplementsPropertiesHandler(): void
    {
        $source = $this->readFile('public/js/files-space.js');

        self::assertNotSame('', $source, 'files-space.js must be readable');
        self::assertStringContainsString('function populatePropertiesModal', $source);
        self::assertStringContainsString('function openPropertiesModal', $source);
        self::assertStringContainsString('function resetPropertiesModal', $source);
        self::assertStringContainsString('function formatPropertiesDate', $source);
        self::assertStringContainsString("'[data-files-row-action=\"properties\"]'", $source);
        self::assertStringContainsString("getElementById('filesPropertiesModal')", $source);
        self::assertStringContainsString('filesPropertiesUrlTemplate', $source);
    }

    /**
     * @brief The FilesController must declare the GET /files/{id}/properties
     *        route and the corresponding public method with the expected
     *        Doxygen header.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testFilesControllerDeclaresPropertiesRoute(): void
    {
        $source = $this->readFile('src/Controller/FilesController.php');

        self::assertNotSame('', $source, 'FilesController.php must be readable');
        self::assertStringContainsString("name: 'files_properties'", $source);
        self::assertStringContainsString("'/files/{id}/properties'", $source);
        self::assertStringContainsString("methods: ['GET']", $source);
        self::assertStringContainsString('public function properties(', $source);
        self::assertStringContainsString('@author Stephane H.', $source);
    }

    /**
     * @brief Every locale must ship the new files.action.properties leaf and
     *        the files.properties.* tree consumed by the modal.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testRequiredPropertiesKeysExistInAllLocales(): void
    {
        $locales = ['fr', 'en', 'de', 'lt', 'no'];
        $requiredLeafs = [
            'properties:',
            'preview_alt:',
            'never_expires:',
            'no_recipients:',
            'recipients:',
            'recipient_until:',
            'recipient_no_expiry:',
            'public_token:',
            'public_validity:',
            'public_no_expiry:',
            'loading:',
            'error:',
        ];

        foreach ($locales as $locale) {
            $source = $this->readFile('translations/messages.' . $locale . '.yaml');
            self::assertNotSame('', $source, 'Locale file must be readable: ' . $locale);

            foreach ($requiredLeafs as $needle) {
                self::assertStringContainsString(
                    $needle,
                    $source,
                    sprintf('Locale "%s" is missing translation leaf for "%s"', $locale, $needle)
                );
            }

            self::assertMatchesRegularExpression(
                '/^  action:[\s\S]*?\n    properties:\s+/m',
                $source,
                sprintf('Locale "%s" is missing files.action.properties', $locale)
            );
            self::assertMatchesRegularExpression(
                '/^  properties:\s*\n    title:\s+/m',
                $source,
                sprintf('Locale "%s" is missing files.properties.title block', $locale)
            );
            self::assertStringContainsString(
                "\n      friends:",
                $source,
                sprintf('Locale "%s" is missing files.properties.section.friends', $locale)
            );
        }
    }
}
