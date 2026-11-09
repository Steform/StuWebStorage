<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DownloadDiagnosticEventRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @brief Purge old download diagnostic rows according to retention window.
 */
#[AsCommand(
    name: 'app:download-diagnostics:purge',
    description: 'Purge old download diagnostic events',
)]
final class DownloadDiagnosticsPurgeCommand extends Command
{
    public function __construct(
        private readonly DownloadDiagnosticEventRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention in days', 30);
    }

    /**
     * @brief Delete rows older than configured retention days.
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @return int
     * @date 2026-07-07
     * @author Stephane H.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d days', $days));
        $deleted = $this->repository->purgeOlderThan($cutoff);
        $output->writeln(sprintf('Purged %d rows older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}
