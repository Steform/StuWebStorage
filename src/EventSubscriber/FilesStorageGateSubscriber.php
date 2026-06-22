<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\File\FilesStorageFeatureService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @brief Block all files storage HTTP routes with 404 when the module is disabled.
 */
final class FilesStorageGateSubscriber implements EventSubscriberInterface
{
    /**
     * @param FilesStorageFeatureService $filesStorageFeatureService Files module feature flag.
     */
    public function __construct(
        private readonly FilesStorageFeatureService $filesStorageFeatureService,
    ) {
    }

    /**
     * @brief Subscribe to kernel request event.
     *
     * @param void No input parameter.
     * @return array<string, array<int, int>|int>
     * @date 2026-06-09
     * @author Stephane H.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    /**
     * @brief Return 404 for files module paths when storage is disabled.
     *
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->filesStorageFeatureService->isEnabled()) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();
        if (!$this->isFilesModulePath($pathInfo)) {
            return;
        }

        $event->setResponse(new Response('', Response::HTTP_NOT_FOUND));
    }

    /**
     * @brief Return true when the request path belongs to the files storage module.
     *
     * @param string $pathInfo Request path info.
     * @return bool
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function isFilesModulePath(string $pathInfo): bool
    {
        if ($pathInfo === '/files' || str_starts_with($pathInfo, '/files/')) {
            return true;
        }

        if ($pathInfo === '/admin/files' || str_starts_with($pathInfo, '/admin/files/')) {
            return true;
        }

        if ($pathInfo === '/api/ui-preferences/files') {
            return true;
        }

        if ($pathInfo === '/p' || str_starts_with($pathInfo, '/p/')) {
            return true;
        }

        if ($pathInfo === '/download/public' || str_starts_with($pathInfo, '/download/public/')) {
            return true;
        }

        return false;
    }
}
