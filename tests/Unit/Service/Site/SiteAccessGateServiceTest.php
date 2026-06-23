<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Site;

use App\Entity\SiteAccessGateSettings;
use App\Repository\SiteAccessGateSettingsRepository;
use App\Service\Site\SiteAccessGateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for platform site settings service.
 */
final class SiteAccessGateServiceTest extends TestCase
{
    /**
     * @brief Maintenance mode flag is persisted from admin form payload.
     *
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function testUpdateMaintenanceSettingsPersistsFlag(): void
    {
        $settings = new SiteAccessGateSettings();
        $repository = $this->createMock(SiteAccessGateSettingsRepository::class);
        $repository->method('getOrCreateSingleton')->willReturn($settings);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new SiteAccessGateService($repository, $entityManager, 50);

        $service->updateMaintenanceSettings(true, 'Scheduled maintenance tonight');

        self::assertTrue($settings->isMaintenanceModeEnabled());
        self::assertSame('Scheduled maintenance tonight', $settings->getMaintenanceMessage());
    }

    /**
     * @brief Antibot threshold is clamped and persisted from admin form payload.
     *
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function testUpdateAntibotSettingsClampsThreshold(): void
    {
        $settings = new SiteAccessGateSettings();
        $repository = $this->createMock(SiteAccessGateSettingsRepository::class);
        $repository->method('getOrCreateSingleton')->willReturn($settings);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new SiteAccessGateService($repository, $entityManager, 50);

        $service->updateAntibotSettings(120);

        self::assertSame(100, $settings->getAntibotThreshold());
    }

    /**
     * @brief Default antibot threshold is used when stored value is zero.
     *
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function testGetAntibotThresholdFallsBackToDefault(): void
    {
        $settings = (new SiteAccessGateSettings())->setAntibotThreshold(0);
        $repository = $this->createMock(SiteAccessGateSettingsRepository::class);
        $repository->method('getOrCreateSingleton')->willReturn($settings);

        $service = new SiteAccessGateService(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            42,
        );

        self::assertSame(42, $service->getAntibotThreshold());
    }
}
