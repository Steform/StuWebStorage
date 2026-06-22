<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class UserDeletionSnapshot.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\UserDeletionSnapshotRepository')]
#[ORM\Table(name: 'user_deletion_snapshot')]
#[ORM\Index(name: 'idx_user_deletion_snapshot_target', columns: ['target_user_id'])]
#[ORM\Index(name: 'idx_user_deletion_snapshot_status', columns: ['status'])]
#[ORM\Index(name: 'idx_user_deletion_snapshot_created', columns: ['created_at'])]
class UserDeletionSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $targetUserId;

    #[ORM\Column(type: 'text')]
    private string $ciphertext;

    #[ORM\Column(length: 255)]
    private string $signature;

    #[ORM\Column(length: 32)]
    private string $algo;

    #[ORM\Column(length: 64)]
    private string $keyVersion;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $restoredAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $purgedAt = null;

    #[ORM\Column(length: 32)]
    private string $status;

    /**
     * @brief Build encrypted user deletion snapshot aggregate.
     * @param int $targetUserId Deleted user identifier.
     * @param string $ciphertext Encrypted snapshot payload.
     * @param string $signature Payload integrity signature.
     * @param string $algo Cipher algorithm identifier.
     * @param string $keyVersion Encryption key version label.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(int $targetUserId, string $ciphertext, string $signature, string $algo, string $keyVersion)
    {
        $this->targetUserId = $targetUserId;
        $this->ciphertext = $ciphertext;
        $this->signature = $signature;
        $this->algo = $algo;
        $this->keyVersion = $keyVersion;
        $this->createdAt = new DateTimeImmutable();
        $this->status = 'captured';
    }

    /**
     * @brief Get snapshot identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get target user identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getTargetUserId(): int
    {
        return $this->targetUserId;
    }

    /**
     * @brief Get encrypted snapshot payload.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getCiphertext(): string
    {
        return $this->ciphertext;
    }

    /**
     * @brief Get payload integrity signature.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getSignature(): string
    {
        return $this->signature;
    }

    /**
     * @brief Get cryptographic algorithm identifier.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getAlgo(): string
    {
        return $this->algo;
    }

    /**
     * @brief Get encryption key version label.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getKeyVersion(): string
    {
        return $this->keyVersion;
    }

    /**
     * @brief Mark snapshot as restored by rollback undo.
     * @param DateTimeImmutable $restoredAt Restore datetime.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function markRestored(DateTimeImmutable $restoredAt): void
    {
        $this->restoredAt = $restoredAt;
        $this->status = 'restored';
    }

    /**
     * @brief Mark snapshot as re-purged by rollback redo.
     * @param DateTimeImmutable $purgedAt Purge datetime.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function markPurged(DateTimeImmutable $purgedAt): void
    {
        $this->purgedAt = $purgedAt;
        $this->status = 'purged';
    }
}
