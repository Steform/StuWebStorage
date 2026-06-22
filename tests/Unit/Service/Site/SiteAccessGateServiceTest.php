<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Site;

use App\Entity\SiteAccessGateSettings;
use App\Repository\SiteAccessGateSettingsRepository;
use App\Service\Site\SiteAccessGateService;
use App\Service\Site\SiteAccessGateSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * @brief Unit tests for site access gate note verification.
 */
final class SiteAccessGateServiceTest extends TestCase
{
    /**
     * @brief Valid bypass note grants session access.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testVerifyBypassNoteAndGrantAcceptsMatchingNote(): void
    {
        $settings = (new SiteAccessGateSettings())->setBypassNote('beta-access');

        $repository = $this->createMock(SiteAccessGateSettingsRepository::class);
        $repository->method('getOrCreateSingleton')->willReturn($settings);

        $sessionService = $this->buildSessionService();
        $service = new SiteAccessGateService(
            $repository,
            $sessionService,
            $this->createMock(EntityManagerInterface::class),
        );

        self::assertTrue($service->verifyBypassNoteAndGrant('beta-access'));
        self::assertTrue($sessionService->isAccessGranted());
    }

    /**
     * @brief Enabling gate without bypass note returns validation error key.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testUpdateSettingsRequiresBypassNoteWhenEnabled(): void
    {
        $settings = new SiteAccessGateSettings();
        $repository = $this->createMock(SiteAccessGateSettingsRepository::class);
        $repository->method('getOrCreateSingleton')->willReturn($settings);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new SiteAccessGateService(
            $repository,
            $this->buildSessionService(),
            $entityManager,
        );

        self::assertSame(
            'storage.access_gate.error.bypass_note_required',
            $service->updateSettings(true, 'Locked', '')
        );
    }

    /**
     * @brief Build session service backed by an in-memory request session.
     *
     * @return SiteAccessGateSessionService
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function buildSessionService(): SiteAccessGateSessionService
    {
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return new SiteAccessGateSessionService($stack);
    }
}
