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
 * @brief Unit tests for site antibot gate settings.
 */
final class SiteAccessGateServiceTest extends TestCase
{
    /**
     * @brief Grant access stores session validity timestamp.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testGrantAccessStoresSessionGrant(): void
    {
        $sessionService = $this->buildSessionService();
        $service = new SiteAccessGateService(
            $this->buildRepository(new SiteAccessGateSettings()),
            $sessionService,
            $this->createMock(EntityManagerInterface::class),
        );

        $service->grantAccess();
        self::assertTrue($sessionService->isAccessGranted());
    }

    /**
     * @brief Invalid threshold returns validation error key.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testUpdateSettingsRejectsInvalidThreshold(): void
    {
        $settings = new SiteAccessGateSettings();
        $repository = $this->buildRepository($settings);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new SiteAccessGateService(
            $repository,
            $this->buildSessionService(),
            $entityManager,
        );

        self::assertSame(
            'storage.access_gate.error.invalid_threshold',
            $service->updateSettings(true, 150)
        );
    }

    /**
     * @brief Valid settings persist enabled flag and threshold.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testUpdateSettingsPersistsThreshold(): void
    {
        $settings = new SiteAccessGateSettings();
        $repository = $this->buildRepository($settings);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new SiteAccessGateService(
            $repository,
            $this->buildSessionService(),
            $entityManager,
        );

        self::assertNull($service->updateSettings(true, 65));
        self::assertTrue($settings->isEnabled());
        self::assertSame(65, $settings->getAntibotThreshold());
    }

    /**
     * @brief Maintenance settings persist enabled flag and message.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testUpdateMaintenanceSettingsPersistsValues(): void
    {
        $settings = new SiteAccessGateSettings();
        $repository = $this->buildRepository($settings);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new SiteAccessGateService(
            $repository,
            $this->buildSessionService(),
            $entityManager,
        );

        $service->updateMaintenanceSettings(true, 'Scheduled downtime');
        self::assertTrue($settings->isMaintenanceModeEnabled());
        self::assertSame('Scheduled downtime', $settings->getMaintenanceMessage());
    }

    /**
     * @brief Build repository mock returning fixed settings singleton.
     *
     * @param SiteAccessGateSettings $settings Settings entity.
     * @return SiteAccessGateSettingsRepository
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function buildRepository(SiteAccessGateSettings $settings): SiteAccessGateSettingsRepository
    {
        $repository = $this->createMock(SiteAccessGateSettingsRepository::class);
        $repository->method('getOrCreateSingleton')->willReturn($settings);

        return $repository;
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
