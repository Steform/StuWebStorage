<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class SharedFile.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\SharedFileRepository')]
#[ORM\Table(name: 'shared_file')]
#[ORM\Index(name: 'idx_shared_file_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_shared_file_updated_at', columns: ['updated_at'])]
#[ORM\Index(name: 'idx_shared_file_file_extension', columns: ['file_extension'])]
#[ORM\Index(name: 'idx_shared_file_is_public', columns: ['is_public'])]
#[ORM\Index(name: 'idx_shared_file_public_expires_at', columns: ['public_expires_at'])]
class SharedFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $ownerUserId;

    #[ORM\ManyToOne(targetEntity: Folder::class)]
    #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Folder $folder = null;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    /**
     * @deprecated since Sprint 22 (2026-04-28). Kept for backward compatibility while the migration to is_public + public_expires_at is rolling out. Will be removed by Version20260428110000.
     */
    #[ORM\Column(length: 32)]
    private string $visibility;

    #[ORM\Column(length: 128, unique: true)]
    private string $publicToken;

    #[ORM\Column(length: 255)]
    private string $originalFileName;

    #[ORM\Column(type: 'bigint')]
    private string $byteSize;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $uploadedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 32)]
    private string $fileExtension = '';

    /**
     * @deprecated since Sprint 22 (2026-04-28). Replaced by public_expires_at for the public channel and per-grant expiration for friends. Will be removed by Version20260428110000.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'is_public', type: 'boolean', options: ['default' => false])]
    private bool $isPublic = false;

    #[ORM\Column(name: 'public_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $publicExpiresAt = null;

    #[ORM\Column(name: 'public_password_enabled', type: 'boolean', options: ['default' => false])]
    private bool $publicPasswordEnabled = false;

    #[ORM\Column(name: 'public_password_hash', length: 255, nullable: true)]
    private ?string $publicPasswordHash = null;

    #[ORM\Column(name: 'public_password_secret', type: 'text', nullable: true)]
    private ?string $publicPasswordSecret = null;

    /**
     * @brief Build shared file aggregate.
     * @param int $ownerUserId Owner user identifier.
     * @param string $storagePath Storage path.
     * @param string $visibility Legacy visibility token ('public' or 'private') kept for transition; mirrored to is_public.
     * @param string $publicToken Public token.
     * @param string $originalFileName Original display file name.
     * @param int|string $byteSize Payload size in bytes.
     * @param DateTimeImmutable|null $uploadedAt Upload timestamp.
     * @param DateTimeImmutable|null $expiresAt Legacy file-level expiration instant (mirrored to public_expires_at when visibility is public).
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(
        int $ownerUserId,
        string $storagePath,
        string $visibility,
        string $publicToken,
        string $originalFileName = 'file',
        int|string $byteSize = 0,
        ?DateTimeImmutable $uploadedAt = null,
        ?DateTimeImmutable $expiresAt = null
    ) {
        $this->ownerUserId = $ownerUserId;
        $this->storagePath = $storagePath;
        $this->visibility = $visibility;
        $this->publicToken = $publicToken;
        $this->originalFileName = $originalFileName;
        $this->byteSize = (string) $byteSize;
        $this->uploadedAt = $uploadedAt ?? new DateTimeImmutable();
        $this->updatedAt = $this->uploadedAt;
        $this->fileExtension = self::normalizeFileExtension($originalFileName);
        $this->expiresAt = $expiresAt;
        $this->isPublic = $visibility === 'public';
        $this->publicExpiresAt = $this->isPublic ? $expiresAt : null;
    }

    /**
     * @brief Normalize a lower-case extension token from a display file name.
     * @param string $originalFileName Original display file name.
     * @return string
     * @date 2026-04-27
     * @author Stephane H.
     */
    public static function normalizeFileExtension(string $originalFileName): string
    {
        $originalFileName = trim($originalFileName);
        if ($originalFileName === '' || !str_contains($originalFileName, '.')) {
            return '';
        }

        $ext = strtolower(substr($originalFileName, strrpos($originalFileName, '.') + 1));
        $ext = preg_replace('/[^a-z0-9_-]/', '', $ext) ?? '';

        return substr($ext, 0, 32);
    }

    /**
     * @brief Mark row as modified at current instant.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @brief Whether the legacy file-level expiration instant is in the past.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     * @deprecated since Sprint 22 (2026-04-28). Use isPublicShareActive() for public channel; per-grant expiration for friends.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new DateTimeImmutable();
    }

    /**
     * @brief Get legacy visibility mode token ('public' or 'private').
     * @param void No input parameter.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     * @deprecated since Sprint 22 (2026-04-28). Use isPublic()/getIsPublic().
     */
    public function getVisibility(): string
    {
        return $this->visibility;
    }

    /**
     * @brief Set legacy visibility mode token ('public' or 'private') and mirror it to is_public.
     * @param string $visibility Visibility mode.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     * @deprecated since Sprint 22 (2026-04-28). Use setIsPublic().
     */
    public function setVisibility(string $visibility): void
    {
        $this->visibility = $visibility;
        $this->isPublic = $visibility === 'public';
    }

    /**
     * @brief Whether the public channel flag is enabled (regardless of expiration).
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * @brief Alias for isPublic() to keep getter semantics consistent across the entity API.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getIsPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * @brief Toggle the public channel flag and keep the legacy visibility mirror in sync.
     * @param bool $isPublic New public flag value.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->visibility = $isPublic ? 'public' : 'private';
        if (!$isPublic) {
            $this->publicExpiresAt = null;
        }
    }

    /**
     * @brief Get the public channel expiration instant (null = unlimited).
     * @param void No input parameter.
     * @return DateTimeImmutable|null
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getPublicExpiresAt(): ?DateTimeImmutable
    {
        return $this->publicExpiresAt;
    }

    /**
     * @brief Replace the public channel expiration instant. Pass null to disable expiration.
     * @param DateTimeImmutable|null $publicExpiresAt Expiration instant or null for unlimited.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function setPublicExpiresAt(?DateTimeImmutable $publicExpiresAt): void
    {
        $this->publicExpiresAt = $publicExpiresAt;
    }

    /**
     * @brief Resolve the public-channel expiration instant for owner UI (modal prefill), including legacy rows where only expires_at is populated.
     * @param void No input parameter.
     * @return DateTimeImmutable|null Same semantics as public_expires_at for display; null when no public expiration is configured.
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function getEffectivePublicExpiresAtForOwnerUi(): ?DateTimeImmutable
    {
        if ($this->publicExpiresAt !== null) {
            return $this->publicExpiresAt;
        }
        if ($this->isPublic) {
            return $this->expiresAt;
        }
        $visibilityNorm = strtolower(trim($this->visibility));

        if ($visibilityNorm === 'public') {
            return $this->expiresAt;
        }

        return null;
    }

    /**
     * @brief Whether the public channel is currently active (enabled and not expired), including legacy rows where visibility is public before is_public backfill.
     * @param void No input parameter.
     * @return bool
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function isPublicShareActive(): bool
    {
        if ($this->isPublic) {
            if ($this->publicExpiresAt === null) {
                return true;
            }

            return $this->publicExpiresAt > new DateTimeImmutable();
        }

        $visibilityNorm = strtolower(trim($this->visibility));
        if ($visibilityNorm === 'public') {
            if ($this->expiresAt === null) {
                return true;
            }

            return $this->expiresAt > new DateTimeImmutable();
        }

        return false;
    }

    /**
     * @brief Whether a public expiration instant is configured (regardless of being already past or not).
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function hasPublicExpiration(): bool
    {
        return $this->isPublic && $this->publicExpiresAt !== null;
    }

    /**
     * @brief Whether the listing should show the public-expiration clock icon (active link with a finite expiry configured).
     * @param void No input parameter.
     * @return bool True when public sharing works now and an expiry instant exists for owner UI.
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function shouldShowPublicExpirationClockInListing(): bool
    {
        return $this->isPublicShareActive() && $this->getEffectivePublicExpiresAtForOwnerUi() !== null;
    }

    /**
     * @brief Whether the public channel is enabled but its expiration instant is past.
     * @param void No input parameter.
     * @return bool
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function isPublicExpired(): bool
    {
        if ($this->isPublic) {
            if ($this->publicExpiresAt === null) {
                return false;
            }

            return $this->publicExpiresAt <= new DateTimeImmutable();
        }

        $visibilityNorm = strtolower(trim($this->visibility));
        if ($visibilityNorm === 'public' && $this->expiresAt !== null) {
            return $this->expiresAt <= new DateTimeImmutable();
        }

        return false;
    }

    /**
     * @brief Get shared file identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get owner user identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getOwnerUserId(): int
    {
        return $this->ownerUserId;
    }

    /**
     * @brief Get attached folder or null when file is in user root.
     * @param void No input parameter.
     * @return Folder|null
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    /**
     * @brief Attach file to a folder (or null for root).
     * @param Folder|null $folder Target folder.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function setFolder(?Folder $folder): void
    {
        $this->folder = $folder;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Get shared storage path.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * @brief Set storage path after relocation.
     * @param string $storagePath Absolute storage path.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function setStoragePath(string $storagePath): void
    {
        $this->storagePath = $storagePath;
    }

    /**
     * @brief Get public token.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getPublicToken(): string
    {
        return $this->publicToken;
    }

    /**
     * @brief Replace public token (e.g. after revoking public visibility).
     * @param string $publicToken New token value.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function setPublicToken(string $publicToken): void
    {
        $this->publicToken = $publicToken;
    }

    /**
     * @brief Get original display file name.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function getOriginalFileName(): string
    {
        return $this->originalFileName;
    }

    /**
     * @brief Set original display file name.
     * @param string $originalFileName Display file name.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function setOriginalFileName(string $originalFileName): void
    {
        $this->originalFileName = $originalFileName;
        $this->fileExtension = self::normalizeFileExtension($originalFileName);
    }

    /**
     * @brief Get lower-case normalized file extension token.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function getFileExtension(): string
    {
        return $this->fileExtension;
    }

    /**
     * @brief Replace normalized extension token (internal metadata updates).
     * @param string $fileExtension Normalized extension token.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function setFileExtension(string $fileExtension): void
    {
        $this->fileExtension = substr($fileExtension, 0, 32);
    }

    /**
     * @brief Get last modification timestamp for listing and sorting.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @brief Set last modification timestamp (migration or tests).
     * @param DateTimeImmutable $updatedAt Modification instant.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function setUpdatedAt(DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * @brief Get payload byte size.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function getByteSize(): int
    {
        return (int) $this->byteSize;
    }

    /**
     * @brief Set payload byte size.
     * @param int $byteSize Size in bytes.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function setByteSize(int $byteSize): void
    {
        $this->byteSize = (string) $byteSize;
    }

    /**
     * @brief Get upload timestamp.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function getUploadedAt(): DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    /**
     * @brief Get the legacy file-level expiration instant.
     * @param void No input parameter.
     * @return DateTimeImmutable|null
     * @date 2026-04-28
     * @author Stephane H.
     * @deprecated since Sprint 22 (2026-04-28). Use getPublicExpiresAt() for public channel; per-grant expiration for friends.
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @brief Set the legacy file-level expiration instant; mirrored to public_expires_at when isPublic is true.
     * @param DateTimeImmutable|null $expiresAt Expiration instant or null for none.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     * @deprecated since Sprint 22 (2026-04-28). Use setPublicExpiresAt() and per-grant expiration.
     */
    public function setExpiresAt(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
        if ($this->isPublic) {
            $this->publicExpiresAt = $expiresAt;
        }
    }

    /**
     * @brief Whether an optional public-download password is enabled for this link.
     * @param void No input parameter.
     * @return bool
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function isPublicPasswordEnabled(): bool
    {
        return $this->publicPasswordEnabled;
    }

    /**
     * @brief Toggle optional public-download password (credentials managed by PublicShareService).
     * @param bool $enabled Enabled flag.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function setPublicPasswordEnabled(bool $enabled): void
    {
        $this->publicPasswordEnabled = $enabled;
    }

    /**
     * @brief Get password hasher output for anonymous download verification.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function getPublicPasswordHash(): ?string
    {
        return $this->publicPasswordHash;
    }

    /**
     * @brief Persist password hash for public download verification.
     * @param string|null $hash Hashed password or null when disabled.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function setPublicPasswordHash(?string $hash): void
    {
        $this->publicPasswordHash = $hash;
    }

    /**
     * @brief Get encrypted plaintext secret for owner UI (nullable when disabled).
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function getPublicPasswordSecret(): ?string
    {
        return $this->publicPasswordSecret;
    }

    /**
     * @brief Persist encrypted owner-visible secret payload.
     * @param string|null $secret Encrypted blob or null.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function setPublicPasswordSecret(?string $secret): void
    {
        $this->publicPasswordSecret = $secret;
    }

    /**
     * @brief Clear hash and encrypted secret (used when disabling password).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function clearPublicPasswordCredentials(): void
    {
        $this->publicPasswordHash = null;
        $this->publicPasswordSecret = null;
    }

    /**
     * @brief Whether email TOTP alone is enough (no share-password step).
     * @param void No input parameter.
     * @return bool
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function isPublicPasswordGateActive(): bool
    {
        return $this->publicPasswordEnabled
            && $this->publicPasswordHash !== null
            && $this->publicPasswordHash !== ''
            && $this->isPublicShareActive();
    }
}
