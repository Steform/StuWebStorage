<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @brief Import vscode-icons used by the file extension resolver.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
#[AsCommand(
    name: 'app:file-icons:import',
    description: 'Import vscode-icons listed in config/icons/vscode-icons-used.txt',
)]
final class FileIconsImportCommand extends Command
{
    private const LIST_PATH = 'config/icons/vscode-icons-used.txt';

    /**
     * @param string $projectDir Application root directory.
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * @brief Import configured icon suffixes through ux:icons:import.
     *
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @return int
     * @date 2026-06-24
     * @author Stephane H.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $listFile = $this->projectDir.'/'.self::LIST_PATH;
        if (!is_readable($listFile)) {
            $io->error(sprintf('Missing icon list file: %s', self::LIST_PATH));

            return Command::FAILURE;
        }

        $suffixes = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            file($listFile, FILE_IGNORE_NEW_LINES) ?: [],
        )));

        if ($suffixes === []) {
            $io->warning('No icon suffixes found to import.');

            return Command::SUCCESS;
        }

        $iconNames = array_map(
            static fn (string $suffix): string => 'vscode-icons:'.$suffix,
            $suffixes,
        );

        $application = $this->getApplication();
        if ($application === null || !$application->has('ux:icons:import')) {
            $io->error('Command ux:icons:import is not available. Run composer require symfony/ux-icons first.');

            return Command::FAILURE;
        }

        $importInput = new ArrayInput([
            'command' => 'ux:icons:import',
            'icons' => $iconNames,
        ]);
        $importInput->setInteractive(false);

        $exitCode = $application->find('ux:icons:import')->run($importInput, $output);
        if ($exitCode === Command::SUCCESS) {
            $io->success(sprintf('Imported %d vscode-icons.', \count($iconNames)));
        }

        return $exitCode;
    }
}
