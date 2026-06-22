<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for translated JSON errors in shareJsonOrRedirect.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class ShareJsonOrRedirectTranslationContractTest extends TestCase
{
    /**
     * @brief Read repository source file.
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief shareJsonOrRedirect JSON branch must return translated message and key.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testShareJsonOrRedirectReturnsTranslatedJsonMessage(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('private function shareJsonOrRedirect(Request $request, string $messageKey, int $statusCode): Response', $source);
        self::assertStringContainsString('$this->translator->trans($messageKey, [], \'messages\', (string) $request->getLocale())', $source);
        self::assertStringContainsString("'message_key' => \$messageKey", $source);
    }
}
