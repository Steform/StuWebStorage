<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Infrastructure\RuntimePermissionFailure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @brief Convert uncaught exceptions on XHR/JSON APIs into JSON, never an HTML 500 that the UI calls “unreachable”.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class JsonApiExceptionSubscriber implements EventSubscriberInterface
{
    /**
     * @brief Construct the JSON API exception translator.
     * @param TranslatorInterface $translator Translator.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @brief Subscribe before Symfony’s HTML error page.
     * @return array<string, array{0: string, 1: int}>
     * @date 2026-11-09
     * @author Stephane H.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 16],
        ];
    }

    /**
     * @brief Replace the HTML error page with a JSON payload for XHR/JSON clients.
     * @param ExceptionEvent $event Kernel exception event.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!$this->wantsJson($request)) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($throwable instanceof HttpExceptionInterface && !RuntimePermissionFailure::matches($throwable)) {
            return;
        }

        $permission = RuntimePermissionFailure::matches($throwable);
        $messageKey = $permission
            ? RuntimePermissionFailure::FLASH_KEY
            : 'files.upload.xhr_server_error';
        $locale = (string) $request->getLocale();
        $message = $this->translator->trans($messageKey, ['%status%' => '500'], 'messages', $locale);

        $event->setResponse(new JsonResponse([
            'status' => 'error',
            'message_key' => $messageKey,
            'message' => $message,
        ], 500));
    }

    /**
     * @brief Whether the client asked for JSON (XHR or Accept).
     * @param Request $request HTTP request.
     * @return bool
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function wantsJson(Request $request): bool
    {
        if (strtolower((string) $request->headers->get('X-Requested-With')) === 'xmlhttprequest') {
            return true;
        }

        return str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }
}
