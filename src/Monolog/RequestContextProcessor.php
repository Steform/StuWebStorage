<?php

namespace App\Monolog;

use App\Entity\User;
use Monolog\LogRecord;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class RequestContextProcessor.
 */
class RequestContextProcessor
{
    /**
     * @brief Build request context processor.
     * @param RequestStack $requestStack Request stack helper.
     * @param Security $security Security helper.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security
    ) {
    }

    /**
     * @brief Add request and user context to log record.
     * @param LogRecord $record Monolog log record payload.
     * @return LogRecord
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->security->getUser();

        $extra = $record->extra;
        $extra['request_id'] = $request?->attributes->get('request_id');
        $extra['route'] = $request?->attributes->get('_route');
        $extra['path'] = $request?->getPathInfo();
        $extra['user_id'] = $user instanceof User ? $user->getId() : null;

        return $record->with(extra: $extra);
    }
}
