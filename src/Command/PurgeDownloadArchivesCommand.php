<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DownloadArchiveRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @brief Purge expired virtual download archive manifests.
 * @date 2026-11-02
 * @author Stephane H.
 */
#[AsCommand(name: 'app:download-archives:purge', description: 'Delete expired download_archive rows')]
final class PurgeDownloadArchivesCommand extends Command
{
    /**
     * @brief Construct command.
     * @param DownloadArchiveRepository $downloadArchiveRepository Archive repository.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly DownloadArchiveRepository $downloadArchiveRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deleted = $this->downloadArchiveRepository->purgeExpired(new \DateTimeImmutable());
        $io->success(sprintf('Purged %d expired archive(s).', $deleted));

        return Command::SUCCESS;
    }
}
