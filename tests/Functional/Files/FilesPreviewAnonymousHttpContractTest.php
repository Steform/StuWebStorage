<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @brief Minimal HTTP smoke for files preview route without authenticated session (expect non-200).
 * @date 2026-05-06
 * @author Stephane H.
 */
final class FilesPreviewAnonymousHttpContractTest extends KernelTestCase
{
    /**
     * @brief Anonymous requests must not receive decrypted preview bytes (redirect or error, not 200 OK).
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testAnonymousPreviewRequestDoesNotReturnOk(): void
    {
        self::bootKernel();
        $kernel = static::getContainer()->get(HttpKernelInterface::class);
        $response = $kernel->handle(Request::create('/files/preview/1', 'GET'));

        self::assertNotSame(200, $response->getStatusCode());
    }
}
