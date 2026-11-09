<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * @brief Persist one diagnostic event for download lifecycle analysis.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\DownloadDiagnosticEventRepository')]
#[ORM\Table(name: 'download_diagnostic_event')]
#[ORM\Index(name: 'idx_dde_download_id', columns: ['download_id'])]
#[ORM\Index(name: 'idx_dde_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_dde_phase_status', columns: ['phase', 'status'])]
final class DownloadDiagnosticEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'download_id', length: 64)]
    private string $downloadId;

    #[ORM\Column(length: 64)]
    private string $phase;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(name: 'owner_user_id', type: 'integer', nullable: true)]
    private ?int $ownerUserId;

    #[ORM\Column(name: 'shared_file_id', type: 'integer', nullable: true)]
    private ?int $sharedFileId;

    #[ORM\Column(name: 'actor_type', length: 32, nullable: true)]
    private ?string $actorType;

    #[ORM\Column(name: 'actor_identity_hash', length: 128, nullable: true)]
    private ?string $actorIdentityHash;

    #[ORM\Column(name: 'ip_hash', length: 128, nullable: true)]
    private ?string $ipHash;

    #[ORM\Column(name: 'user_agent_hash', length: 128, nullable: true)]
    private ?string $userAgentHash;

    #[ORM\Column(name: 'bytes_total', type: 'bigint', nullable: true)]
    private ?string $bytesTotal;

    #[ORM\Column(name: 'bytes_sent', type: 'bigint', nullable: true)]
    private ?string $bytesSent;

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    private ?int $durationMs;

    #[ORM\Column(name: 'http_status', type: 'integer', nullable: true)]
    private ?int $httpStatus;

    #[ORM\Column(name: 'error_code', length: 128, nullable: true)]
    private ?string $errorCode;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage;

    #[ORM\Column(name: 'extra_json', type: 'json', nullable: true)]
    private ?array $extraJson = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @brief Build diagnostic event aggregate.
     * @param string $downloadId Correlation identifier.
     * @param string $phase Lifecycle phase.
     * @param string $status Status token.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function __construct(string $downloadId, string $phase, string $status)
    {
        $this->downloadId = $downloadId;
        $this->phase = $phase;
        $this->status = $status;
        $this->createdAt = new DateTimeImmutable();
        $this->ownerUserId = null;
        $this->sharedFileId = null;
        $this->actorType = null;
        $this->actorIdentityHash = null;
        $this->ipHash = null;
        $this->userAgentHash = null;
        $this->bytesTotal = null;
        $this->bytesSent = null;
        $this->durationMs = null;
        $this->httpStatus = null;
        $this->errorCode = null;
        $this->errorMessage = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDownloadId(): string
    {
        return $this->downloadId;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSharedFileId(): ?int
    {
        return $this->sharedFileId;
    }

    public function getBytesTotal(): ?int
    {
        return $this->bytesTotal === null ? null : (int) $this->bytesTotal;
    }

    public function getBytesSent(): ?int
    {
        return $this->bytesSent === null ? null : (int) $this->bytesSent;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setOwnerUserId(?int $ownerUserId): void
    {
        $this->ownerUserId = $ownerUserId;
    }

    public function setSharedFileId(?int $sharedFileId): void
    {
        $this->sharedFileId = $sharedFileId;
    }

    public function setActorType(?string $actorType): void
    {
        $this->actorType = $actorType;
    }

    public function setActorIdentityHash(?string $actorIdentityHash): void
    {
        $this->actorIdentityHash = $actorIdentityHash;
    }

    public function setIpHash(?string $ipHash): void
    {
        $this->ipHash = $ipHash;
    }

    public function setUserAgentHash(?string $userAgentHash): void
    {
        $this->userAgentHash = $userAgentHash;
    }

    public function setBytesTotal(?int $bytesTotal): void
    {
        $this->bytesTotal = $bytesTotal === null ? null : (string) $bytesTotal;
    }

    public function setBytesSent(?int $bytesSent): void
    {
        $this->bytesSent = $bytesSent === null ? null : (string) $bytesSent;
    }

    public function setDurationMs(?int $durationMs): void
    {
        $this->durationMs = $durationMs;
    }

    public function setHttpStatus(?int $httpStatus): void
    {
        $this->httpStatus = $httpStatus;
    }

    public function setErrorCode(?string $errorCode): void
    {
        $this->errorCode = $errorCode;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function setExtraJson(?array $extraJson): void
    {
        $this->extraJson = $extraJson;
    }
}
