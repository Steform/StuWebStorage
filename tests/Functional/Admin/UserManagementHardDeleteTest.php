<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\RoleGovernanceService;
use App\Service\File\FilesStorageFeatureService;
use App\Service\Admin\TrustedDeviceAdminService;
use App\Service\Admin\UserHardDeletePurgeService;
use App\Service\Admin\UserHardDeleteSnapshotService;
use App\Service\Admin\UserHardDeleteVaultService;
use App\Service\Admin\UserManagementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class UserManagementHardDeleteTest extends TestCase
{
    /**
     * @brief Ensure hard delete creates snapshot and purges target user.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testHardDeleteUserCreatesSnapshotAndPurges(): void
    {
        $actor = (new User())->setEmail('actor@example.com')->setPseudonym('actor')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $target = (new User())->setEmail('target@example.com')->setPseudonym('target')->setRoles(['ROLE_USER'])->setActive(true);
        $this->setUserId($actor, 1);
        $this->setUserId($target, 2);

        $userRepository = $this->createMock(UserRepository::class);
        $roleGovernanceService = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $snapshotService = $this->createMock(UserHardDeleteSnapshotService::class);
        $snapshotService->method('buildSnapshotPayload')->willReturn([
            'targetUserId' => 2,
            'user' => ['id' => 2],
            'sharedFilesOwned' => [],
        ]);
        $vaultService = $this->createMock(UserHardDeleteVaultService::class);
        $vaultService->method('encryptPayload')->willReturn([
            'ciphertext' => 'cipher',
            'signature' => 'sig',
            'algo' => 'aes-256-cbc',
            'keyVersion' => 'v1',
        ]);
        $purgeService = $this->createMock(UserHardDeletePurgeService::class);
        $purgeService->method('purgeFromSnapshot')->willReturn(['user' => 1]);

        $service = new UserManagementService(
            $userRepository,
            $roleGovernanceService,
            $trustedDeviceAdminService,
            $entityManager,
            $snapshotService,
            $vaultService,
            $purgeService
        );

        $result = $service->hardDeleteUser($actor, $target, 'cleanup');

        self::assertNull($result);
    }

    /**
     * @brief Ensure hard delete rejects self deletion requests.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testHardDeleteUserRejectsSelfDeletion(): void
    {
        $actor = (new User())->setEmail('admin@example.com')->setPseudonym('admin')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $target = (new User())->setEmail('admin@example.com')->setPseudonym('admin')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $this->setUserId($actor, 10);
        $this->setUserId($target, 10);

        $userRepository = $this->createMock(UserRepository::class);
        $roleGovernanceService = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $snapshotService = $this->createMock(UserHardDeleteSnapshotService::class);
        $vaultService = $this->createMock(UserHardDeleteVaultService::class);
        $purgeService = $this->createMock(UserHardDeletePurgeService::class);
        $service = new UserManagementService(
            $userRepository,
            $roleGovernanceService,
            $trustedDeviceAdminService,
            $entityManager,
            $snapshotService,
            $vaultService,
            $purgeService
        );

        $result = $service->hardDeleteUser($actor, $target, 'cleanup');

        self::assertSame('admin.users.error.self_hard_delete_forbidden', $result);
    }

    /**
     * @brief Set private user identifier for tests.
     * @param User $user Target user object.
     * @param int $id Identifier value.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function setUserId(User $user, int $id): void
    {
        $reflection = new \ReflectionClass($user);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($user, $id);
    }
}
