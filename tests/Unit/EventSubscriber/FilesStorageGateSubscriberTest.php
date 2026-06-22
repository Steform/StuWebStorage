<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\FilesStorageGateSubscriber;
use App\Service\File\FilesStorageFeatureService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @brief Unit tests for files storage HTTP gate subscriber.
 */
final class FilesStorageGateSubscriberTest extends TestCase
{
    /**
     * @brief Subscriber must register on kernel request.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testSubscribedEvents(): void
    {
        self::assertArrayHasKey(KernelEvents::REQUEST, FilesStorageGateSubscriber::getSubscribedEvents());
    }

    /**
     * @brief Disabled module must return 404 on /files.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testReturns404ForFilesRouteWhenDisabled(): void
    {
        $subscriber = new FilesStorageGateSubscriber(new FilesStorageFeatureService(false));
        $event = $this->createMainRequestEvent('/files');

        $subscriber->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_NOT_FOUND, $event->getResponse()?->getStatusCode());
    }

    /**
     * @brief Disabled module must return 404 on public share routes.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testReturns404ForPublicShareRouteWhenDisabled(): void
    {
        $subscriber = new FilesStorageGateSubscriber(new FilesStorageFeatureService(false));
        $event = $this->createMainRequestEvent('/p/example-token');

        $subscriber->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_NOT_FOUND, $event->getResponse()?->getStatusCode());
    }

    /**
     * @brief Enabled module must not intercept unrelated routes.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testDoesNotBlockUnrelatedRouteWhenDisabled(): void
    {
        $subscriber = new FilesStorageGateSubscriber(new FilesStorageFeatureService(false));
        $event = $this->createMainRequestEvent('/cv/');

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /**
     * @brief Enabled module must not block files routes.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testDoesNotBlockFilesRouteWhenEnabled(): void
    {
        $subscriber = new FilesStorageGateSubscriber(new FilesStorageFeatureService(true));
        $event = $this->createMainRequestEvent('/files');

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /**
     * @brief Path matcher must cover admin, API, and public download routes.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testPathMatcherCoversModuleRoutes(): void
    {
        $subscriber = new FilesStorageGateSubscriber(new FilesStorageFeatureService(true));

        self::assertTrue($subscriber->isFilesModulePath('/files'));
        self::assertTrue($subscriber->isFilesModulePath('/files/upload'));
        self::assertTrue($subscriber->isFilesModulePath('/admin/files'));
        self::assertTrue($subscriber->isFilesModulePath('/admin/files/owners/suggest'));
        self::assertTrue($subscriber->isFilesModulePath('/api/ui-preferences/files'));
        self::assertTrue($subscriber->isFilesModulePath('/p/token'));
        self::assertTrue($subscriber->isFilesModulePath('/download/public/challenge'));
        self::assertFalse($subscriber->isFilesModulePath('/dashboard'));
        self::assertFalse($subscriber->isFilesModulePath('/cv/'));
    }

    /**
     * @brief Build a main request kernel event for the given path.
     *
     * @param string $path Request path.
     * @return RequestEvent
     * @date 2026-06-09
     * @author Stephane H.
     */
    private function createMainRequestEvent(string $path): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST);
    }
}
