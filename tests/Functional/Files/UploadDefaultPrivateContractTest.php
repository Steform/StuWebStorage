<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for upload defaults in FilesController.
 */
final class UploadDefaultPrivateContractTest extends TestCase
{
    /**
     * @brief Read a repository file and return raw source.
     * @param string $relativePath Repository-relative path.
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
     * @brief Isolate the upload action source block so share endpoints in the same controller do not invalidate contracts.
     * @param string $controllerSource Full FilesController.php contents.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function extractUploadMethodSource(string $controllerSource): string
    {
        $startMarker = 'public function upload(Request $request';
        $start = strpos($controllerSource, $startMarker);
        self::assertNotFalse($start, 'upload() must exist in FilesController');
        $next = strpos($controllerSource, "\n    public function ", $start + \strlen($startMarker));
        self::assertNotFalse($next, 'upload() must be followed by another public method');

        return substr($controllerSource, $start, $next - $start);
    }

    /**
     * @brief Upload flow must persist files as private with no public expiration at creation time.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testUploadFlowBuildsPrivateSharedFileByDefault(): void
    {
        $full = $this->readSource('src/Controller/FilesController.php');
        $source = $this->extractUploadMethodSource($full);

        self::assertMatchesRegularExpression(
            '/\$sharedFile\s*=\s*new\s+SharedFile\([\s\S]*\'private\'[\s\S]*null\s*,\s*null\s*\)/',
            $source,
            'Upload must instantiate SharedFile as private with two trailing null legacy expiry arguments.'
        );
    }

    /**
     * @brief Upload flow must not parse direct share inputs from the upload form contract.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testUploadFlowDoesNotReadShareFormInputs(): void
    {
        $full = $this->readSource('src/Controller/FilesController.php');
        $source = $this->extractUploadMethodSource($full);

        self::assertStringNotContainsString('$request->request->get(\'visibility\'', $source);
        self::assertStringNotContainsString('$request->request->get(\'expires_at\'', $source);
        self::assertStringNotContainsString('$request->request->get(\'grantee_ids\'', $source);
    }

    /**
     * @brief Upload action must support JSON responses for XHR clients via shared helpers.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testUploadActionUsesJsonHelpersForAjaxClients(): void
    {
        $full = $this->readSource('src/Controller/FilesController.php');
        $source = $this->extractUploadMethodSource($full);

        self::assertStringContainsString('uploadJsonErrorOrRedirect', $source);
        self::assertStringContainsString('uploadSuccessJsonOrRedirect($request, $translator)', $source);
    }

    /**
     * @brief uploadSuccessJsonOrRedirect must expose translated success message for JSON clients and keep flash only for HTML posts.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testUploadSuccessJsonOrRedirectIncludesTranslatedMessageForAjax(): void
    {
        $full = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString(
            'private function uploadSuccessJsonOrRedirect(Request $request, TranslatorInterface $translator)',
            $full
        );
        self::assertStringContainsString(
            "'message' => \$translator->trans('files.flash.uploaded', [], 'messages', \$locale),",
            $full
        );
        $expectsJsonPos = strpos($full, 'if ($this->expectsJson($request))');
        $jsonMessagePos = strpos($full, "'message' => \$translator->trans('files.flash.uploaded', [], 'messages', \$locale),");
        $addFlashPos = strpos($full, "\$this->addFlash('success', 'files.flash.uploaded');");
        self::assertNotFalse($expectsJsonPos);
        self::assertNotFalse($jsonMessagePos);
        self::assertNotFalse($addFlashPos);
        self::assertLessThan($addFlashPos, $expectsJsonPos);
        self::assertLessThan($addFlashPos, $jsonMessagePos);
    }
}
