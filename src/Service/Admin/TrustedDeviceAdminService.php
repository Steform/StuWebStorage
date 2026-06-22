<?php

namespace App\Service\Admin;

use App\Repository\TrustedDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service TrustedDeviceAdminService.
 */
class TrustedDeviceAdminService
{
    /**
     * @brief Build trusted device admin service.
     * @param TrustedDeviceRepository $trustedDeviceRepository Trusted device repository.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(
        private readonly TrustedDeviceRepository $trustedDeviceRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @brief List trusted devices for user.
     * @param int $userId User identifier.
     * @return array<int, array{id: int, fingerprint: string, trustedUntil: string}>
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function listByUserId(int $userId): array
    {
        $devices = $this->trustedDeviceRepository->findBy(['userId' => $userId], ['id' => 'DESC']);
        $result = [];
        foreach ($devices as $device) {
            $result[] = [
                'id' => (int) $device->getId(),
                'fingerprint' => $device->getDeviceFingerprint(),
                'trustedUntil' => $device->getTrustedUntil()->format(DATE_ATOM),
            ];
        }

        return $result;
    }

    /**
     * @brief Revoke one trusted device for user.
     * @param int $userId User identifier.
     * @param int $deviceId Device identifier.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function revokeOne(int $userId, int $deviceId): bool
    {
        $device = $this->trustedDeviceRepository->findOneBy(['id' => $deviceId, 'userId' => $userId]);
        if ($device === null) {
            return false;
        }

        $this->entityManager->remove($device);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @brief Revoke all trusted devices for user.
     * @param int $userId User identifier.
     * @return int
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function revokeAll(int $userId): int
    {
        $devices = $this->trustedDeviceRepository->findBy(['userId' => $userId]);
        foreach ($devices as $device) {
            $this->entityManager->remove($device);
        }
        $this->entityManager->flush();

        return count($devices);
    }
}
