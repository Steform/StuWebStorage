<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\BugReportAdminController;
use App\Entity\BugReport;
use App\Entity\User;
use App\Repository\BugReportRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class BugReportAdminControllerTest extends TestCase
{
    /**
     * @brief Ensure admin listing renders existing bug reports.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testIndexRendersBugReportList(): void
    {
        $reporter = (new User())->setEmail('reporter@example.com')->setPseudonym('reporter')->setRoles(['ROLE_USER']);
        $this->setUserId($reporter, 3);
        $report = new BugReport($reporter, 'Action', 'Observed', null, 'files_index', '/files', null, 'fr', 'dark', null, null, null, null, null, null, null);

        $repository = $this->createMock(BugReportRepository::class);
        $repository->expects(self::once())
            ->method('findForAdminList')
            ->with('', '', '', null, null, false)
            ->willReturn([$report]);
        $security = $this->createMock(Security::class);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $controller = new BugReportAdminController($repository, $security, $csrfTokenManager);
        $twig = new Environment(new ArrayLoader([
            'admin/bug_reports/index.html.twig' => '{{ reports|length }}',
        ]));

        $response = $controller->index($twig, Request::create('/admin/bug-reports'));

        self::assertSame('1', (string) $response->getContent());
    }

    /**
     * @brief Ensure status update resolves ticket and persists changes.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testUpdateStatusResolvesTicket(): void
    {
        $reporter = (new User())->setEmail('reporter@example.com')->setPseudonym('reporter')->setRoles(['ROLE_USER']);
        $resolver = (new User())->setEmail('admin@example.com')->setPseudonym('admin')->setRoles(['ROLE_ADMIN']);
        $this->setUserId($reporter, 3);
        $this->setUserId($resolver, 1);
        $report = new BugReport($reporter, 'Action', 'Observed', null, 'files_index', '/files', null, 'fr', 'dark', null, null, null, null, null, null, null);

        $repository = $this->createMock(BugReportRepository::class);
        $repository->method('find')->with(10)->willReturn($report);
        $repository->expects(self::once())->method('save')->with($report);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($resolver);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $controller = new BugReportAdminController($repository, $security, $csrfTokenManager);

        $response = $controller->updateStatus(Request::create('/admin/bug-reports/10/status', 'POST', [
            '_csrf_token' => 'valid',
            'status' => 'resolved',
        ]), 10);

        self::assertSame(BugReport::STATUS_RESOLVED, $report->getStatus());
        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * @brief Set private user identifier for tests.
     * @param User $user Target user object.
     * @param int $id Identifier value.
     * @return void
     * @date 2026-05-06
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
