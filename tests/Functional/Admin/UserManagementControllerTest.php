<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\UserController;
use App\Entity\User;
use App\Service\Admin\RoleGovernanceService;
use App\Service\Admin\TrustedDeviceAdminService;
use App\Service\Admin\UserManagementService;
use App\Service\File\UserStorageQuotaService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class UserManagementControllerTest extends TestCase
{
    /**
     * @brief Ensure admin users index renders with provided users.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function testIndexRendersUserList(): void
    {
        $user = (new User())->setEmail('user@example.com')->setPseudonym('tester')->setRoles(['ROLE_USER']);
        $this->setUserId($user, 20);
        $userManagementService = $this->createMock(UserManagementService::class);
        $userManagementService->method('listUsers')->willReturn(['items' => [$user], 'total' => 1]);
        $roleGovernanceService = $this->createMock(RoleGovernanceService::class);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $security = $this->createMock(Security::class);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $controller = new UserController(
            $userManagementService,
            $roleGovernanceService,
            $trustedDeviceAdminService,
            $security,
            $csrfTokenManager,
            $this->createQuotaService(),
        );
        $twig = new Environment(new ArrayLoader([
            'admin/users/index.html.twig' => '{{ users|length }}',
        ]));

        $response = $controller->index($twig, Request::create('/admin/users'));

        self::assertSame('1', (string) $response->getContent());
    }

    /**
     * @brief Ensure delete route delegates to hard delete service on valid request.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testDeleteCallsHardDeleteWithValidCsrfToken(): void
    {
        $targetUser = (new User())->setEmail('target@example.com')->setPseudonym('target')->setRoles(['ROLE_USER'])->setActive(true);
        $actorUser = (new User())->setEmail('actor@example.com')->setPseudonym('actor')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $this->setUserId($targetUser, 20);
        $this->setUserId($actorUser, 10);

        $userManagementService = $this->createMock(UserManagementService::class);
        $userManagementService->method('findUserById')->with(20)->willReturn($targetUser);
        $userManagementService->expects(self::once())
            ->method('hardDeleteUser')
            ->with($actorUser, $targetUser, 'cleanup')
            ->willReturn(null);

        $roleGovernanceService = $this->createMock(RoleGovernanceService::class);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($actorUser);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->expects(self::once())
            ->method('isTokenValid')
            ->with(self::isInstanceOf(CsrfToken::class))
            ->willReturn(true);
        $controller = new UserController(
            $userManagementService,
            $roleGovernanceService,
            $trustedDeviceAdminService,
            $security,
            $csrfTokenManager,
            $this->createQuotaService(),
        );

        $response = $controller->delete(Request::create('/admin/users/20/delete', 'POST', [
            '_csrf_token' => 'valid-token',
            'confirm_phrase' => 'DELETE',
            'hard_delete_reason' => 'cleanup',
        ]), 20);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users', $response->headers->get('Location'));
    }

    /**
     * @brief Ensure delete route blocks hard delete when CSRF token is invalid.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testDeleteSkipsSoftDeleteWhenCsrfTokenIsInvalid(): void
    {
        $targetUser = (new User())->setEmail('target@example.com')->setPseudonym('target')->setRoles(['ROLE_USER'])->setActive(true);
        $actorUser = (new User())->setEmail('actor@example.com')->setPseudonym('actor')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $this->setUserId($targetUser, 20);
        $this->setUserId($actorUser, 10);

        $userManagementService = $this->createMock(UserManagementService::class);
        $userManagementService->method('findUserById')->with(20)->willReturn($targetUser);
        $userManagementService->expects(self::never())->method('hardDeleteUser');
        $roleGovernanceService = $this->createMock(RoleGovernanceService::class);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($actorUser);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);
        $controller = new UserController(
            $userManagementService,
            $roleGovernanceService,
            $trustedDeviceAdminService,
            $security,
            $csrfTokenManager,
            $this->createQuotaService(),
        );

        $response = $controller->delete(Request::create('/admin/users/20/delete', 'POST', ['_csrf_token' => 'invalid-token']), 20);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users/20', $response->headers->get('Location'));
    }

    /**
     * @brief Ensure delete route blocks hard delete when confirmation phrase is invalid.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testDeleteSkipsHardDeleteWhenConfirmationPhraseIsInvalid(): void
    {
        $targetUser = (new User())->setEmail('target@example.com')->setPseudonym('target')->setRoles(['ROLE_USER'])->setActive(true);
        $actorUser = (new User())->setEmail('actor@example.com')->setPseudonym('actor')->setRoles(['ROLE_ADMIN'])->setActive(true);
        $this->setUserId($targetUser, 20);
        $this->setUserId($actorUser, 10);

        $userManagementService = $this->createMock(UserManagementService::class);
        $userManagementService->method('findUserById')->with(20)->willReturn($targetUser);
        $userManagementService->expects(self::never())->method('hardDeleteUser');
        $roleGovernanceService = $this->createMock(RoleGovernanceService::class);
        $trustedDeviceAdminService = $this->createMock(TrustedDeviceAdminService::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($actorUser);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $controller = new UserController(
            $userManagementService,
            $roleGovernanceService,
            $trustedDeviceAdminService,
            $security,
            $csrfTokenManager,
            $this->createQuotaService(),
        );

        $response = $controller->delete(Request::create('/admin/users/20/delete', 'POST', [
            '_csrf_token' => 'valid-token',
            'confirm_phrase' => 'INVALID',
        ]), 20);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users/20', $response->headers->get('Location'));
    }

    /**
     * @brief Build quota service with empty repository doubles for controller tests.
     *
     * @return UserStorageQuotaService
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function createQuotaService(): UserStorageQuotaService
    {
        return new UserStorageQuotaService(
            $this->createMock(\App\Repository\SharedFileRepository::class),
            $this->createMock(\App\Repository\UserRepository::class),
            0,
        );
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
