<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class Folder.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\FolderRepository')]
#[ORM\Table(name: 'folder')]
#[ORM\Index(name: 'idx_folder_owner_parent', columns: ['owner_user_id', 'parent_folder_id'])]
#[ORM\Index(name: 'idx_folder_parent', columns: ['parent_folder_id'])]
#[ORM\Index(name: 'idx_folder_owner', columns: ['owner_user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_folder_owner_parent_name', columns: ['owner_user_id', 'parent_folder_id', 'name_normalized'])]
class Folder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $ownerUserId;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_folder_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $nameNormalized;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPublicShareEnabled = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $publicShareExpiresAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $friendsShareUserIds = [];

    #[ORM\Column(length: 128, unique: true, nullable: true)]
    private ?string $publicFolderToken = null;

    #[ORM\Column(name: 'public_password_enabled', type: 'boolean', options: ['default' => false])]
    private bool $publicPasswordEnabled = false;

    #[ORM\Column(name: 'public_password_hash', length: 255, nullable: true)]
    private ?string $publicPasswordHash = null;

    #[ORM\Column(name: 'public_password_secret', type: 'text', nullable: true)]
    private ?string $publicPasswordSecret = null;

    /**
     * @brief Build folder aggregate.
     * @param int $ownerUserId Owner user identifier.
     * @param string $name Display folder name.
     * @param Folder|null $parent Parent folder or null for root level.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function __construct(int $ownerUserId, string $name, ?self $parent = null)
    {
        $this->ownerUserId = $ownerUserId;
        $this->setName($name);
        $this->parent = $parent;
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @brief Get folder identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-04-29
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
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getOwnerUserId(): int
    {
        return $this->ownerUserId;
    }

    /**
     * @brief Get parent folder.
     * @param void No input parameter.
     * @return Folder|null
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * @brief Set parent folder.
     * @param Folder|null $parent Parent folder.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Get folder display name.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @brief Set folder name and normalized token.
     * @param string $name Display folder name.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function setName(string $name): void
    {
        $trimmed = trim($name);
        $this->name = $trimmed === '' ? 'folder' : mb_substr($trimmed, 0, 190);
        $this->nameNormalized = self::normalizeName($this->name);
        $this->touchUpdatedAt();
    }

    /**
     * @brief Get normalized folder name.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getNameNormalized(): string
    {
        return $this->nameNormalized;
    }

    /**
     * @brief Get creation timestamp.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @brief Get update timestamp.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @brief Update modification timestamp.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @brief Tell whether folder-level public share policy is enabled.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function isPublicShareEnabled(): bool
    {
        return $this->isPublicShareEnabled;
    }

    /**
     * @brief Enable or disable folder-level public share policy.
     * @param bool $enabled Public policy flag.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function setPublicShareEnabled(bool $enabled): void
    {
        $this->isPublicShareEnabled = $enabled;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Get folder-level public share expiration.
     * @param void No input parameter.
     * @return DateTimeImmutable|null
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getPublicShareExpiresAt(): ?DateTimeImmutable
    {
        return $this->publicShareExpiresAt;
    }

    /**
     * @brief Set folder-level public share expiration.
     * @param DateTimeImmutable|null $expiresAt Optional expiration datetime.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function setPublicShareExpiresAt(?DateTimeImmutable $expiresAt): void
    {
        $this->publicShareExpiresAt = $expiresAt;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Whether folder-level public sharing is currently usable (policy on and optional expiration strictly in the future).
     * @param void No input parameter.
     * @return bool True when policy is enabled and there is no expiry or expiry instant is after now.
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function isPublicShareEffectivelyActive(): bool
    {
        if (!$this->isPublicShareEnabled) {
            return false;
        }
        if ($this->publicShareExpiresAt === null) {
            return true;
        }

        return $this->publicShareExpiresAt > new DateTimeImmutable();
    }

    /**
     * @brief Get folder-level friends-share grantee ids.
     * @param void No input parameter.
     * @return array<int, int>
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getFriendsShareUserIds(): array
    {
        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, (array) $this->friendsShareUserIds)));
    }

    /**
     * @brief Set folder-level friends-share grantee ids.
     * @param array<int, int> $userIds Grantee user identifiers.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function setFriendsShareUserIds(array $userIds): void
    {
        $normalized = [];
        foreach ($userIds as $userId) {
            $id = (int) $userId;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }
        $this->friendsShareUserIds = array_values($normalized);
        $this->touchUpdatedAt();
    }

    /**
     * @brief Get opaque token for anonymous public folder landing (ZIP subtree scope).
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function getPublicFolderToken(): ?string
    {
        return $this->publicFolderToken;
    }

    /**
     * @brief Set opaque token for anonymous public folder landing (null revokes external URL).
     * @param string|null $publicFolderToken Hex token or null when sharing is off.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function setPublicFolderToken(?string $publicFolderToken): void
    {
        $this->publicFolderToken = $publicFolderToken;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Whether optional public-folder download password is enabled.
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
     * @brief Toggle optional public-folder password.
     * @param bool $enabled Enabled flag.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function setPublicPasswordEnabled(bool $enabled): void
    {
        $this->publicPasswordEnabled = $enabled;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Get password hash for anonymous verification.
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
     * @brief Set password hash for anonymous verification.
     * @param string|null $hash Hashed password or null.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function setPublicPasswordHash(?string $hash): void
    {
        $this->publicPasswordHash = $hash;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Get encrypted owner-visible secret payload.
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
     * @brief Set encrypted owner-visible secret payload.
     * @param string|null $secret Encrypted blob or null.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function setPublicPasswordSecret(?string $secret): void
    {
        $this->publicPasswordSecret = $secret;
        $this->touchUpdatedAt();
    }

    /**
     * @brief Clear hash and encrypted secret.
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
     * @brief Whether visitors must pass share-password after email TOTP.
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
            && $this->isPublicShareEffectivelyActive()
            && $this->publicFolderToken !== null
            && $this->publicFolderToken !== '';
    }

    /**
     * @brief Normalize folder names for uniqueness checks.
     * @param string $name Raw folder name.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
