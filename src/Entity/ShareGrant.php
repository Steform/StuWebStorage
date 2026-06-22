<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class ShareGrant.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\ShareGrantRepository')]
#[ORM\Table(name: 'share_grant')]
#[ORM\Index(name: 'idx_share_grant_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_share_grant_shared_file_id', columns: ['shared_file_id'])]
#[ORM\Index(name: 'idx_share_grant_grantee_user_id', columns: ['grantee_user_id'])]
class ShareGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $sharedFileId;

    #[ORM\Column(type: 'integer')]
    private int $granteeUserId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    /**
     * @brief Build share grant relation with optional grant-level expiration.
     * @param int $sharedFileId Shared file identifier.
     * @param int $granteeUserId Grantee user identifier.
     * @param DateTimeImmutable|null $expiresAt Optional grant-level expiration instant.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function __construct(int $sharedFileId, int $granteeUserId, ?DateTimeImmutable $expiresAt = null)
    {
        $this->sharedFileId = $sharedFileId;
        $this->granteeUserId = $granteeUserId;
        $this->expiresAt = $expiresAt;
    }

    /**
     * @brief Get share grant identifier.
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
     * @brief Get shared file identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getSharedFileId(): int
    {
        return $this->sharedFileId;
    }

    /**
     * @brief Get grantee user identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-26
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
     * @date 2026-04-27
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
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function setExpiresAt(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    /**
     * @brief Whether this grant is past its own expiration instant.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new DateTimeImmutable();
    }
}
