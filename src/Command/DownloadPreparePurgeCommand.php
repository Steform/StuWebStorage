<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\File\DownloadPrepareService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @brief Purge expired prepared download jobs and plaintext artifacts.
 * @author Stephane H.
 * @date 2026-07-07
 */
#[AsCommand(
    name: 'app:download:purge-expired',
    description: 'Purge expired prepared download jobs and temporary plaintext files',
)]
final class DownloadPreparePurgeCommand extends Command
{
    public function __construct(
        private readonly DownloadPrepareService $downloadPrepareService,
    ) {
        parent::__construct();
    }

    /**
     * @brief Execute purge across all download prepare namespaces.
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @return int
     * @date 2026-07-07
     * @author Stephane H.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = $this->downloadPrepareService->purgeExpiredAll();
        $output->writeln(sprintf('Purged %d prepared download job(s).', $purged));

        return Command::SUCCESS;
    }
}
