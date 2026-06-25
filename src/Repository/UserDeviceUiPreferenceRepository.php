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

    /**
     * @brief Return the most recently updated sort preference for one user across devices.
     * @param User $user Target user.
     * @return array{field: string, direction: string}|null
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function findLatestSortPreferenceByUser(User $user): ?array
    {
        $row = $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$row instanceof UserDeviceUiPreference) {
            return null;
        }

        return [
            'field' => $row->getFilesSortField(),
            'direction' => $row->getFilesSortDirection(),
        ];
    }

    /**
     * @brief Propagate sort preference to every persisted device row for one user.
     * @param User $user Target user.
     * @param string $field Whitelisted sort field key.
     * @param string $direction Sort direction (`asc` or `desc`).
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function syncSortPreferenceForUser(User $user, string $field, string $direction): void
    {
        $this->createQueryBuilder('p')
            ->update()
            ->set('p.filesSortField', ':field')
            ->set('p.filesSortDirection', ':direction')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->setParameter('field', $field)
            ->setParameter('direction', $direction)
            ->getQuery()
            ->execute();
    }
}
