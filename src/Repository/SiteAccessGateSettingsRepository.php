<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SiteAccessGateSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteAccessGateSettings>
 */
class SiteAccessGateSettingsRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry Doctrine registry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteAccessGateSettings::class);
    }

    /**
     * @brief Load singleton gate settings row, creating defaults when missing.
     *
     * @param void No input parameter.
     * @return SiteAccessGateSettings
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getOrCreateSingleton(): SiteAccessGateSettings
    {
        /** @var SiteAccessGateSettings|null $settings */
        $settings = $this->createQueryBuilder('s')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($settings instanceof SiteAccessGateSettings) {
            return $settings;
        }

        $settings = new SiteAccessGateSettings();
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();

        return $settings;
    }
}
