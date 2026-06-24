<?php

declare(strict_types=1);

namespace App\Command;

use App\File\FileExtensionIconResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @brief Export vscode icon suffixes used by the file extension resolver.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
#[AsCommand(
    name: 'app:file-icons:export',
    description: 'Write config/icons/vscode-icons-used.txt from FileExtensionIconResolver',
)]
final class FileIconsExportCommand extends Command
{
    private const OUTPUT_PATH = 'config/icons/vscode-icons-used.txt';

    /**
     * @param FileExtensionIconResolver $iconResolver Extension resolver.
     * @param string $projectDir Application root directory.
     */
    public function __construct(
        private readonly FileExtensionIconResolver $iconResolver,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * @brief Write icon suffix list to config file.
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
        $suffixes = $this->iconResolver->listUsedIconSuffixes();
        $content = implode("\n", $suffixes)."\n";
        $target = $this->projectDir.'/'.self::OUTPUT_PATH;

        (new Filesystem())->dumpFile($target, $content);

        $io->success(sprintf('Exported %d icon suffixes to %s', \count($suffixes), self::OUTPUT_PATH));

        return Command::SUCCESS;
    }
}
