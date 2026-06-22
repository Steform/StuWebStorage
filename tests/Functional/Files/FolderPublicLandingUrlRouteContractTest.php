<?php

namespace App\Tests\Functional\Files;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Contract for the JSON endpoint that resolves a folder public landing URL.
 */
class FolderPublicLandingUrlRouteContractTest extends KernelTestCase
{
    /**
     * @brief Ensure the public-landing-url route is registered with POST and the expected path pattern.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testRouteIsRegistered(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('files_folder_public_landing_url');
        self::assertNotNull($route);
        self::assertStringContainsString('public-landing-url', $route->getPath());
        self::assertTrue(\in_array('POST', $route->getMethods(), true));
    }

    /**
     * @brief Public landing links shared to users must be absolute (scheme + host), not path-only.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilePublicLandingUrlCanBeGeneratedAsAbsolute(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        $url = $router->generate('file_public_landing', ['publicToken' => str_repeat('a', 32)], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertMatchesRegularExpression('#^https?://#', $url);
    }

    /**
     * @brief Anonymous folder landing route must exist with GET and token requirement.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFolderPublicLandingRouteIsRegistered(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('folder_public_landing');
        self::assertNotNull($route);
        self::assertStringContainsString('/p/folder/', $route->getPath());
        self::assertTrue(\in_array('GET', $route->getMethods(), true));
    }

    /**
     * @brief Folder public landing links shared to users must be absolute (scheme + host).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFolderPublicLandingUrlCanBeGeneratedAsAbsolute(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        $url = $router->generate('folder_public_landing', ['publicToken' => str_repeat('b', 32)], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertMatchesRegularExpression('#^https?://#', $url);
    }

    /**
     * @brief One-time public folder ZIP download route must exist for GET.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testDownloadPublicFolderRouteIsRegistered(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('download_public_folder');
        self::assertNotNull($route);
        self::assertStringContainsString('/download/public/folder', $route->getPath());
        self::assertTrue(\in_array('GET', $route->getMethods(), true));
    }
}
