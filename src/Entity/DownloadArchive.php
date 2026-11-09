<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DownloadArchiveRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * @brief Virtual ZIP archive manifest (no blob on disk).
 * @date 2026-11-02
 * @author Stephane H.
 */
#[ORM\Entity(repositoryClass: DownloadArchiveRepository::class)]
#[ORM\Table(name: 'download_archive')]
#[ORM\UniqueConstraint(name: 'uniq_download_archive_token', columns: ['token'])]
#[ORM\Index(name: 'idx_download_archive_actor', columns: ['actor_type', 'actor_id'])]
#[ORM\Index(name: 'idx_download_archive_status_expires', columns: ['status', 'expires_at'])]
class DownloadArchive
{
    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const KIND_SELECTION = 'selection';

    public const KIND_FOLDER = 'folder';

    public const KIND_SHARED_FOLDER = 'shared-folder';

    public const KIND_PUBLIC_FOLDER = 'public-folder';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column(length: 32)]
    private string $kind;

    #[ORM\Column(name: 'actor_type', length: 32)]
    private string $actorType;

    #[ORM\Column(name: 'actor_id', length: 128)]
    private string $actorId;

    #[ORM\Column(name: 'owner_user_id', type: 'integer', nullable: true)]
    private ?int $ownerUserId = null;

    #[ORM\Column(name: 'file_name', length: 255)]
    private string $fileName;

    #[ORM\Column(length: 32)]
    private string $status;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'entries_json', type: 'json')]
    private array $entriesJson = [];

    #[ORM\Column(name: 'total_bytes', type: 'bigint')]
    private string $totalBytes = '0';

    #[ORM\Column(name: 'prepared_bytes', type: 'bigint')]
    private string $preparedBytes = '0';

    #[ORM\Column(name: 'entry_count', type: 'integer')]
    private int $entryCount = 0;

    #[ORM\Column(name: 'skipped_count', type: 'integer')]
    private int $skippedCount = 0;

    #[ORM\Column(name: 'layout_version', type: 'integer')]
    private int $layoutVersion = 1;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(name: 'error_code', length: 128, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'ready_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $readyAt = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'last_access_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastAccessAt = null;

    #[ORM\Column(name: 'preparing_until', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $preparingUntil = null;

    /**
     * @brief Construct archive aggregate.
     * @param string $token Public archive token.
     * @param string $kind Archive kind.
     * @param string $actorType Actor type.
     * @param string $actorId Actor id.
     * @param string $fileName Suggested ZIP file name.
     * @param DateTimeImmutable $expiresAt Expiration instant.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        string $token,
        string $kind,
        string $actorType,
        string $actorId,
        string $fileName,
        DateTimeImmutable $expiresAt,
    ) {
        $this->token = $token;
        $this->kind = $kind;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->fileName = $fileName;
        $this->status = self::STATUS_PREPARING;
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorId(): string
    {
        return $this->actorId;
    }

    public function getOwnerUserId(): ?int
    {
        return $this->ownerUserId;
    }

    public function setOwnerUserId(?int $ownerUserId): void
    {
        $this->ownerUserId = $ownerUserId;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntriesJson(): array
    {
        return $this->entriesJson;
    }

    /**
     * @param list<array<string, mixed>> $entriesJson Entries.
     * @return void
     */
    public function setEntriesJson(array $entriesJson): void
    {
        $this->entriesJson = array_values($entriesJson);
        $this->entryCount = count($this->entriesJson);
    }

    public function getTotalBytes(): int
    {
        return (int) $this->totalBytes;
    }

    public function setTotalBytes(int $totalBytes): void
    {
        $this->totalBytes = (string) $totalBytes;
    }

    public function getPreparedBytes(): int
    {
        return (int) $this->preparedBytes;
    }

    public function setPreparedBytes(int $preparedBytes): void
    {
        $this->preparedBytes = (string) $preparedBytes;
    }

    public function getEntryCount(): int
    {
        return $this->entryCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function setSkippedCount(int $skippedCount): void
    {
        $this->skippedCount = $skippedCount;
    }

    public function getLayoutVersion(): int
    {
        return $this->layoutVersion;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function setEtag(?string $etag): void
    {
        $this->etag = $etag;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): void
    {
        $this->errorCode = $errorCode;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadyAt(): ?DateTimeImmutable
    {
        return $this->readyAt;
    }

    public function setReadyAt(?DateTimeImmutable $readyAt): void
    {
        $this->readyAt = $readyAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getLastAccessAt(): ?DateTimeImmutable
    {
        return $this->lastAccessAt;
    }

    public function touchLastAccess(): void
    {
        $this->lastAccessAt = new DateTimeImmutable();
    }

    public function getPreparingUntil(): ?DateTimeImmutable
    {
        return $this->preparingUntil;
    }

    public function setPreparingUntil(?DateTimeImmutable $preparingUntil): void
    {
        $this->preparingUntil = $preparingUntil;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isExpiredAt(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $this->expiresAt <= $now;
    }
}
