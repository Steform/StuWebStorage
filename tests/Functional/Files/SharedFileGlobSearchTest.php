<?php

namespace App\Tests\Functional\Files;

use App\Entity\SharedFile;
use App\File\SharedFileOwnerListCriteria;
use App\Repository\SharedFileRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Coverage for glob (* / ?) wildcard semantics on the files listing search.
 */
class SharedFileGlobSearchTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SharedFileRepository $repository;
    private int $ownerUserId;

    /**
     * @brief Bootstrap kernel and seed two shared files for the same fictitious owner.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    protected function setUp(): void
    {
        parent::setUp();
        try {
            self::bootKernel();
            $container = static::getContainer();
            $this->entityManager = $container->get(EntityManagerInterface::class);
            $this->repository = $container->get(SharedFileRepository::class);
            $this->ownerUserId = 920000000 + random_int(0, 9999);

            $exeFile = new SharedFile(
                $this->ownerUserId,
                '/tmp/glob-test-setup.exe',
                'private',
                'glob-test-token-exe-'.bin2hex(random_bytes(6)),
                'setup.exe',
                123,
                new DateTimeImmutable('-1 hour')
            );
            $txtFile = new SharedFile(
                $this->ownerUserId,
                '/tmp/glob-test-report.txt',
                'private',
                'glob-test-token-txt-'.bin2hex(random_bytes(6)),
                'report.txt',
                456,
                new DateTimeImmutable('-1 hour')
            );

            $this->entityManager->persist($exeFile);
            $this->entityManager->persist($txtFile);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable for SharedFileGlobSearchTest: '.$e->getMessage());
        }
    }

    /**
     * @brief Remove seeded rows to keep DB clean across runs.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->createQuery('DELETE FROM '.SharedFile::class.' sf WHERE sf.ownerUserId = :owner')
                ->setParameter('owner', $this->ownerUserId)
                ->execute();
        }
        parent::tearDown();
    }

    /**
     * @brief Wildcard *.exe matches setup.exe but excludes report.txt.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testGlobStarExeMatchesOnlyExe(): void
    {
        $rows = $this->repository->findOwnedFilteredAll(
            $this->ownerUserId,
            new SharedFileOwnerListCriteria(searchQuery: '*.exe')
        );

        self::assertCount(1, $rows);
        self::assertSame('setup.exe', $rows[0]->getOriginalFileName());
    }

    /**
     * @brief Wildcard *.txt matches report.txt but excludes setup.exe.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testGlobStarTxtMatchesOnlyTxt(): void
    {
        $rows = $this->repository->findOwnedFilteredAll(
            $this->ownerUserId,
            new SharedFileOwnerListCriteria(searchQuery: '*.txt')
        );

        self::assertCount(1, $rows);
        self::assertSame('report.txt', $rows[0]->getOriginalFileName());
    }

    /**
     * @brief Single-character wildcard ? still anchors literal context.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testGlobQuestionMarkMatchesSingleChar(): void
    {
        $rows = $this->repository->findOwnedFilteredAll(
            $this->ownerUserId,
            new SharedFileOwnerListCriteria(searchQuery: 'setu?.exe')
        );

        self::assertCount(1, $rows);
        self::assertSame('setup.exe', $rows[0]->getOriginalFileName());
    }

    /**
     * @brief Plain (non-glob) search keeps a substring match across name and extension.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testPlainSearchMatchesSubstring(): void
    {
        $rows = $this->repository->findOwnedFilteredAll(
            $this->ownerUserId,
            new SharedFileOwnerListCriteria(searchQuery: 'setup')
        );

        self::assertCount(1, $rows);
        self::assertSame('setup.exe', $rows[0]->getOriginalFileName());
    }

    /**
     * @brief Plain search for "exe" matches via fileExtension column even without wildcard.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testPlainSearchByExtensionStillWorks(): void
    {
        $rows = $this->repository->findOwnedFilteredAll(
            $this->ownerUserId,
            new SharedFileOwnerListCriteria(searchQuery: 'exe')
        );

        $names = array_map(static fn (SharedFile $row): string => $row->getOriginalFileName(), $rows);
        self::assertContains('setup.exe', $names);
        self::assertNotContains('report.txt', $names);
    }
}
