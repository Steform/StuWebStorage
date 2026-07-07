<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SharedFileRepository;
use App\Service\File\V2SegmentIndexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @brief Backfill missing v2 segment index sidecars for encrypted storage files.
 * @author Stephane H.
 * @date 2026-07-07
 */
#[AsCommand(
    name: 'app:storage:build-segment-index',
    description: 'Build missing .cvf2idx sidecars for v2 encrypted storage files',
)]
final class BuildV2SegmentIndexCommand extends Command
{
    public function __construct(
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly V2SegmentIndexService $segmentIndexService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Scan only, do not write index files')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum files to process', '0');
    }

    /**
     * @brief Execute index backfill.
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @return int
     * @date 2026-07-07
     * @author Stephane H.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));

        $processed = 0;
        $built = 0;
        $skipped = 0;

        foreach ($this->sharedFileRepository->findAll() as $sharedFile) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $storagePath = $sharedFile->getStoragePath();
            if ($storagePath === '' || !is_readable($storagePath)) {
                ++$skipped;
                continue;
            }

            ++$processed;
            $ok = $this->segmentIndexService->buildIndexIfMissing($storagePath, $dryRun);
            if ($ok) {
                ++$built;
            }
        }

        $io->success(sprintf(
            'Processed %d file(s); indexed %d; skipped %d%s',
            $processed,
            $built,
            $skipped,
            $dryRun ? ' (dry-run)' : '',
        ));

        return Command::SUCCESS;
    }
}
