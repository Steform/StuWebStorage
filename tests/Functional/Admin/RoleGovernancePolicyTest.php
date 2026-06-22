<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\RoleGovernanceService;
use App\Service\File\FilesStorageFeatureService;
use PHPUnit\Framework\TestCase;

class RoleGovernancePolicyTest extends TestCase
{
    /**
     * @brief Ensure self role change is forbidden.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function testValidateRoleChangeRejectsSelfRoleChange(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $service = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $actor = (new User())->setEmail('admin@example.com')->setRoles(['ROLE_ADMIN']);
        $target = (new User())->setEmail('admin@example.com')->setRoles(['ROLE_ADMIN']);
        $this->setUserId($actor, 10);
        $this->setUserId($target, 10);

        $errorKey = $service->validateRoleChange($actor, $target, ['ROLE_ADMIN']);

        self::assertSame('admin.users.error.self_role_change_forbidden', $errorKey);
    }

    /**
     * @brief Ensure last active admin cannot be downgraded.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function testValidateRoleChangeRejectsLastAdminDowngrade(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('countActiveAdmins')->willReturn(1);
        $service = new RoleGovernanceService($userRepository, new FilesStorageFeatureService(true), ['ROLE_USER', 'ROLE_ADMIN']);
        $actor = (new User())->setEmail('actor@example.com')->setRoles(['ROLE_ADMIN']);
        $target = (new User())->setEmail('target@example.com')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $this->setUserId($actor, 11);
        $this->setUserId($target, 12);

        $errorKey = $service->validateRoleChange($actor, $target, ['ROLE_USER']);

        self::assertSame('admin.users.error.last_admin_protected', $errorKey);
    }

    /**
     * @brief Set private user identifier for tests.
     * @param User $user Target user object.
     * @param int $id Identifier value.
     * @return void
     * @date 2026-04-23
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
