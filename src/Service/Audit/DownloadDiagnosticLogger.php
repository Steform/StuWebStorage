<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\DownloadDiagnosticEvent;
use App\Repository\DownloadDiagnosticEventRepository;

/**
 * @brief Persist sanitized diagnostic events for download lifecycle troubleshooting.
 */
class DownloadDiagnosticLogger
{
    public function __construct(
        private readonly DownloadDiagnosticEventRepository $repository,
        private readonly string $appSecret,
    ) {
    }

    /**
     * @brief Create a new correlation id for one download flow.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function newDownloadId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @brief Persist one diagnostic event with normalized optional fields.
     * @param string $downloadId Correlation identifier.
     * @param string $phase Lifecycle phase.
     * @param string $status Event status.
     * @param array<string, mixed> $context Optional context payload.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function log(string $downloadId, string $phase, string $status, array $context = []): void
    {
        $event = new DownloadDiagnosticEvent($downloadId, $phase, $status);
        $event->setOwnerUserId($this->toNullableInt($context['ownerUserId'] ?? null));
        $event->setSharedFileId($this->toNullableInt($context['sharedFileId'] ?? null));
        $event->setActorType($this->toNullableString($context['actorType'] ?? null, 32));
        $event->setActorIdentityHash($this->hashNullable((string) ($context['actorIdentity'] ?? '')));
        $event->setIpHash($this->hashNullable((string) ($context['ip'] ?? '')));
        $event->setUserAgentHash($this->hashNullable((string) ($context['userAgent'] ?? '')));
        $event->setBytesTotal($this->toNullableInt($context['bytesTotal'] ?? null));
        $event->setBytesSent($this->toNullableInt($context['bytesSent'] ?? null));
        $event->setDurationMs($this->toNullableInt($context['durationMs'] ?? null));
        $event->setHttpStatus($this->toNullableInt($context['httpStatus'] ?? null));
        $event->setErrorCode($this->toNullableString($context['errorCode'] ?? null, 128));
        $event->setErrorMessage($this->toNullableString($context['errorMessage'] ?? null, 4000));
        $event->setExtraJson($this->sanitizeExtra($context['extra'] ?? null));

        $this->repository->save($event);
    }

    private function hashNullable(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, $this->appSecret);
    }

    private function toNullableString(mixed $value, int $maxLen): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLen);
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (!is_int($value) && !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param mixed $extra Input extra context.
     * @return array<string, mixed>|null
     */
    private function sanitizeExtra(mixed $extra): ?array
    {
        if (!is_array($extra)) {
            return null;
        }

        $safe = [];
        foreach ($extra as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $safe[$k] = $v;
            }
        }

        return $safe === [] ? null : $safe;
    }
}
