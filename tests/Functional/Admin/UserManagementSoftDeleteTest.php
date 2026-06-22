<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\RoleGovernanceService;
use App\Service\File\FilesStorageFeatureService;
use App\Service\Admin\TrustedDeviceAdminService;
use App\Service\Admin\UserManagementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class UserManagementSoftDeleteTest extends TestCase
{
    /**
     * @brief Ensure soft delete disables user and revokes trusted devices.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testSoftDeleteUserDisablesTargetAndFlushesChanges(): void
    {
        $actor = (new User())->setEmail('actor@example.com')->setPseudonym('actor')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $target = (new User())->setEmail('target@example.com')->setPseudonym('target')->setRoles(['ROLE_USER'])->setActive(true);
        $this->setUserId($actor, 1);
        $this->setUserId($target, 2);
        $initialSessionVersion = $target->getSessionVersion();

        $userRepository = $this->createMock(UserRepository::class);
        $roleGovernanceService = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $trustedDeviceAdminService->expects(self::once())->method('revokeAll')->with(2)->willReturn(3);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $service = new UserManagementService($userRepository, $roleGovernanceService, $trustedDeviceAdminService, $entityManager);

        $result = $service->softDeleteUser($actor, $target);

        self::assertNull($result);
        self::assertFalse($target->isActive());
        self::assertGreaterThan($initialSessionVersion, $target->getSessionVersion());
    }

    /**
     * @brief Ensure soft delete rejects self-deactivation.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testSoftDeleteUserRejectsSelfDeactivation(): void
    {
        $actor = (new User())->setEmail('admin@example.com')->setPseudonym('admin')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $target = (new User())->setEmail('admin@example.com')->setPseudonym('admin')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $this->setUserId($actor, 10);
        $this->setUserId($target, 10);

        $userRepository = $this->createMock(UserRepository::class);
        $roleGovernanceService = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $trustedDeviceAdminService->expects(self::never())->method('revokeAll');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = new UserManagementService($userRepository, $roleGovernanceService, $trustedDeviceAdminService, $entityManager);

        $result = $service->softDeleteUser($actor, $target);

        self::assertSame('admin.users.error.self_soft_delete_forbidden', $result);
    }

    /**
     * @brief Ensure soft delete rejects last active admin deactivation.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testSoftDeleteUserRejectsLastActiveAdmin(): void
    {
        $actor = (new User())->setEmail('actor@example.com')->setPseudonym('actor')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $target = (new User())->setEmail('target@example.com')->setPseudonym('target')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $this->setUserId($actor, 1);
        $this->setUserId($target, 2);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('countActiveAdmins')->willReturn(1);
        $roleGovernanceService = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $trustedDeviceAdminService->expects(self::never())->method('revokeAll');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = new UserManagementService($userRepository, $roleGovernanceService, $trustedDeviceAdminService, $entityManager);

        $result = $service->softDeleteUser($actor, $target);

        self::assertSame('admin.users.error.last_admin_protected', $result);
    }

    /**
     * @brief Ensure soft delete rejects already inactive user.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testSoftDeleteUserRejectsAlreadyInactiveAccount(): void
    {
        $actor = (new User())->setEmail('actor@example.com')->setPseudonym('actor')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $target = (new User())->setEmail('target@example.com')->setPseudonym('target')->setRoles(['ROLE_USER'])->setActive(false);
        $this->setUserId($actor, 1);
        $this->setUserId($target, 2);

        $userRepository = $this->createMock(UserRepository::class);
        $roleGovernanceService = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $trustedDeviceAdminService->expects(self::never())->method('revokeAll');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = new UserManagementService($userRepository, $roleGovernanceService, $trustedDeviceAdminService, $entityManager);

        $result = $service->softDeleteUser($actor, $target);

        self::assertSame('admin.users.error.already_inactive', $result);
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
