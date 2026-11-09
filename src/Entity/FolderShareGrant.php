<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Friends-channel grant binding one folder subtree to one grantee user.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\FolderShareGrantRepository')]
#[ORM\Table(name: 'folder_share_grant')]
#[ORM\UniqueConstraint(name: 'uniq_folder_share_grant_folder_grantee', columns: ['folder_id', 'grantee_user_id'])]
#[ORM\Index(name: 'idx_folder_share_grant_grantee_expires', columns: ['grantee_user_id', 'expires_at'])]
#[ORM\Index(name: 'idx_folder_share_grant_folder', columns: ['folder_id'])]
class FolderShareGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $folderId;

    #[ORM\Column(type: 'integer')]
    private int $granteeUserId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @brief Build folder share grant with optional expiration.
     * @param int $folderId Shared folder identifier.
     * @param int $granteeUserId Grantee user identifier.
     * @param DateTimeImmutable|null $expiresAt Optional grant-level expiration instant.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function __construct(int $folderId, int $granteeUserId, ?DateTimeImmutable $expiresAt = null)
    {
        $this->folderId = $folderId;
        $this->granteeUserId = $granteeUserId;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * @brief Get folder share grant identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get shared folder identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getFolderId(): int
    {
        return $this->folderId;
    }

    /**
     * @brief Get grantee user identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getGranteeUserId(): int
    {
        return $this->granteeUserId;
    }

    /**
     * @brief Get optional grant-level expiration instant.
     * @param void No input parameter.
     * @return DateTimeImmutable|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @brief Set grant-level expiration instant or null to remove it.
     * @param DateTimeImmutable|null $expiresAt Expiration instant or null.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function setExpiresAt(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    /**
     * @brief Get creation timestamp.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
