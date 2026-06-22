<?php

namespace App\Repository;

use App\Entity\TrustedDevice;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class TrustedDeviceRepository.
 *
 * @extends ServiceEntityRepository<TrustedDevice>
 */
class TrustedDeviceRepository extends ServiceEntityRepository
{
    /**
     * @brief Build trusted device repository.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedDevice::class);
    }

    /**
     * @brief Find active trusted device by user and fingerprint.
     * @param int $userId User identifier.
     * @param string $fingerprint Device fingerprint.
     * @param DateTimeImmutable $now Current datetime.
     * @return TrustedDevice|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function findActiveByUserAndFingerprint(int $userId, string $fingerprint, DateTimeImmutable $now): ?TrustedDevice
    {
        return $this->createQueryBuilder('trusted_device')
            ->andWhere('trusted_device.userId = :userId')
            ->andWhere('trusted_device.deviceFingerprint = :fingerprint')
            ->andWhere('trusted_device.trustedUntil >= :now')
            ->setParameter('userId', $userId)
            ->setParameter('fingerprint', $fingerprint)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @brief Find existing trusted device by user and fingerprint.
     * @param int $userId User identifier.
     * @param string $fingerprint Device fingerprint.
     * @return TrustedDevice|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function findByUserAndFingerprint(int $userId, string $fingerprint): ?TrustedDevice
    {
        return $this->createQueryBuilder('trusted_device')
            ->andWhere('trusted_device.userId = :userId')
            ->andWhere('trusted_device.deviceFingerprint = :fingerprint')
            ->setParameter('userId', $userId)
            ->setParameter('fingerprint', $fingerprint)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
