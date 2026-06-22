<?php

namespace App\Tests\Functional\BugReport;

use App\Controller\BugReportController;
use App\Entity\BugReport;
use App\Entity\User;
use App\Repository\BugReportRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class BugReportControllerTest extends TestCase
{
    /**
     * @brief Ensure submit endpoint persists bug report on valid payload.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testSubmitCreatesBugReportWhenPayloadIsValid(): void
    {
        $actor = (new User())->setEmail('user@example.com')->setPseudonym('user')->setRoles(['ROLE_USER']);
        $this->setUserId($actor, 42);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($actor);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);

        $repository = $this->createMock(BugReportRepository::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (BugReport $report): bool {
                return $report->getReporterUser()->getId() === 42
                    && $report->getStatus() === BugReport::STATUS_NEW
                    && $report->getSeverity() === BugReport::SEVERITY_MAJOR
                    && $report->getPath() === '/files'
                    && $report->getLocale() === 'fr';
            }));

        $controller = new BugReportController($repository, $security, $csrfTokenManager, $logger, '2026.05');
        $request = Request::create('/bug-report/submit', 'POST', [
            '_csrf_token' => 'valid',
            'action_description' => 'Clicked upload',
            'observed_result' => 'Modal never closes',
            'expected_result' => 'Modal should close',
            'severity' => 'major',
            'source_route' => 'files_index',
            'source_path' => '/files',
            'source_query' => 'view=list',
            'source_locale' => 'fr',
            'source_theme' => 'dark',
            'viewport_width' => '1920',
            'viewport_height' => '1080',
            'action_timeline' => '[{"type":"ui_click"}]',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $controller->submit($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/files?view=list', $response->headers->get('Location'));
    }

    /**
     * @brief Ensure submit endpoint rejects invalid CSRF token.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testSubmitRejectsInvalidCsrfToken(): void
    {
        $actor = (new User())->setEmail('user@example.com')->setPseudonym('user')->setRoles(['ROLE_USER']);
        $this->setUserId($actor, 42);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($actor);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->createMock(BugReportRepository::class);
        $repository->expects(self::never())->method('save');

        $controller = new BugReportController($repository, $security, $csrfTokenManager, $logger, '');
        $request = Request::create('/bug-report/submit', 'POST', [
            '_csrf_token' => 'invalid',
            'source_path' => '/cv',
            'source_query' => '',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $controller->submit($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/cv', $response->headers->get('Location'));
    }

    /**
     * @brief Ensure submit endpoint rejects anonymous users.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testSubmitRejectsAnonymousUsers(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->createMock(BugReportRepository::class);

        $controller = new BugReportController($repository, $security, $csrfTokenManager, $logger, '');
        $response = $controller->submit(Request::create('/bug-report/submit', 'POST'));

        self::assertSame(403, $response->getStatusCode());
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
