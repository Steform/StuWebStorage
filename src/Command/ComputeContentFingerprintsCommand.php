<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SharedFile;
use App\Repository\SharedFileRepository;
use App\Service\File\FileEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @brief Backfill plaintext CRC-32 and SHA-256 fingerprints for shared files.
 * @date 2026-11-02
 * @author Stephane H.
 */
#[AsCommand(name: 'app:storage:compute-fingerprints', description: 'Compute missing content_crc32/content_sha256 for shared files')]
final class ComputeContentFingerprintsCommand extends Command
{
    /**
     * @brief Construct command.
     * @param SharedFileRepository $sharedFileRepository File repository.
     * @param FileEncryptionService $fileEncryptionService Encryption helpers.
     * @param EntityManagerInterface $entityManager ORM.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Limit to one owner user id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max files to process', '100')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Wall-clock budget in seconds', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist fingerprints');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $owner = $input->getOption('owner');
        $limit = max(1, (int) $input->getOption('limit'));
        $maxSeconds = max(0, (int) $input->getOption('max-seconds'));
        $dryRun = (bool) $input->getOption('dry-run');
        $deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : null;

        $qb = $this->sharedFileRepository->createQueryBuilder('f')
            ->andWhere('f.contentSha256 IS NULL OR f.contentCrc32 IS NULL')
            ->orderBy('f.id', 'ASC')
            ->setMaxResults($limit);
        if ($owner !== null && $owner !== '') {
            $qb->andWhere('f.ownerUserId = :owner')->setParameter('owner', (int) $owner);
        }

        /** @var list<SharedFile> $files */
        $files = $qb->getQuery()->getResult();
        $done = 0;
        $failed = 0;

        foreach ($files as $sharedFile) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $io->warning('Stopped early: max-seconds budget reached.');
                break;
            }
            $path = $sharedFile->getStoragePath();
            if ($path === '' || !is_readable($path)) {
                ++$failed;
                $io->writeln(sprintf('<error>unreadable #%d</error>', (int) $sharedFile->getId()));
                continue;
            }
            try {
                $fingerprints = $this->fileEncryptionService->computeFingerprintsFromStorage($path);
                if (!$dryRun) {
                    $sharedFile->setContentCrc32($fingerprints['content_crc32']);
                    $sharedFile->setContentSha256($fingerprints['content_sha256']);
                    $this->entityManager->flush();
                }
                ++$done;
                $io->writeln(sprintf(
                    'ok #%d crc=%u sha=%s',
                    (int) $sharedFile->getId(),
                    $fingerprints['content_crc32'],
                    substr($fingerprints['content_sha256'], 0, 12).'…',
                ));
            } catch (\Throwable $e) {
                ++$failed;
                $io->writeln(sprintf('<error>fail #%d %s</error>', (int) $sharedFile->getId(), $e->getMessage()));
            }
        }

        $io->success(sprintf('Processed %d file(s), %d failure(s)%s.', $done, $failed, $dryRun ? ' (dry-run)' : ''));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
