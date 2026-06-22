<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserDeviceUiPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository UserDeviceUiPreferenceRepository.
 *
 * @extends ServiceEntityRepository<UserDeviceUiPreference>
 */
class UserDeviceUiPreferenceRepository extends ServiceEntityRepository
{
    /**
     * @brief Build repository.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDeviceUiPreference::class);
    }

    /**
     * @brief Find preferences for one user and one device.
     * @param User $user Target user.
     * @param string $deviceId Opaque device identifier.
     * @return UserDeviceUiPreference|null
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function findOneByUserAndDeviceId(User $user, string $deviceId): ?UserDeviceUiPreference
    {
        return $this->findOneBy([
            'user' => $user,
            'deviceId' => $deviceId,
        ]);
    }
}
